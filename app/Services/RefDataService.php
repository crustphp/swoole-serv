<?php

namespace App\Services;

use Swoole\Process;
use DB\DBConnectionPool;

use Swoole\Timer as swTimer;
use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Crust\SwooleDb\Selector\TableSelector;
use Swoole\Coroutine\Barrier;
use App\Enum\RefMostActiveEnum;

class RefDataService
{
    protected $server;
    protected $dbConnectionPools;
    protected $postgresDbKey;
    protected $process;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;
    protected $fields;

    const TOPGAINERCOLUMN = 'calculated_value';
    const ERRORLOG = 'error_logs';
    const ALLCHANGEDRECORDS = 'all_changed_records';
    const ALLRECORDS = 'all_records';
    const REFSNAPSHOTCOMPANIES = 'ref_data_snapshot_companies';
    const ISREFDATA = 'is_ref_data';

    public function __construct($server, $process, $postgresDbKey = null)
    {
        $GLOBALS['process_id'] = $process->id;
        $this->server = $server;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
        $this->process = $process;
        $this->worker_id = $this->process->id;

        $app_type_database_driven = config('app_config.app_type_database_driven');
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        if ($app_type_database_driven) {
            $poolKey = makePoolKey($this->worker_id, 'postgres');
            try {
                // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey, 'postgres', 'swoole', true);
                $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key]->create();
            } catch (\Throwable $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getFile() . PHP_EOL;
                echo $e->getLine() . PHP_EOL;
                echo $e->getCode() . PHP_EOL;
                var_dump($e->getTrace());
            }
        }

        $this->objDbPool = $this->dbConnectionPools[$this->worker_id][$swoole_pg_db_key];
        $this->dbFacade = new DbFacade();
        $this->fields = config('app_config.ref_fields');
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
            $companyDetail = null;

            // Aggregate query to get the count from the Refinitive table
            $refCountFromDB = $this->getDataCountFromDB(self::REFSNAPSHOTCOMPANIES);

            if ($refCountFromDB && $refCountFromDB[0]['count'] > 0) {
                // Get Refinintive Indicator data from DB
                $indicatorDataFromDB = $this->getRefDataFromDB(self::REFSNAPSHOTCOMPANIES);

                // If the data is fresh, initialize from the database
                if ($this->isFreshRefData($indicatorDataFromDB)) {
                    $this->loadSwooleTableWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES, $indicatorDataFromDB);
                    var_dump("Data is loaded into the swoole");
                } else {
                    var_dump("There is older data than five minutes");
                    // Get companies details with Ref Data from DB
                    $companyDetailWithRefData = $this->getCompaniesWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES);
                    $isProcessedRefIndicatorData = $this->processRefData($companyDetailWithRefData, self::REFSNAPSHOTCOMPANIES, true);

                    if(!$isProcessedRefIndicatorData) {
                        var_dump('Refinitive API is not returning data at this time');
                    }
                }
            } else {
                var_dump("There is no Refinitive Company data in DB");
                $companyDetail = $this->getCompaniesFromDB();

                $isProcessedRefIndicatorData = $this->processRefData($companyDetail, self::REFSNAPSHOTCOMPANIES);

                if(!$isProcessedRefIndicatorData) {
                    var_dump('Refinitive API is not returning data at this time');
                }

            }

            ////////////////// Code will be removed start /////////

            // Aggregate query to get the count from the Refinitive table
            $refCountFromDB = $this->getDataCountFromDB(RefMostActiveEnum::TOPGAINER->value);

            // Check $refCountFromDB has count greater than zero
            if ($refCountFromDB && $refCountFromDB[0]['count'] > 0) {
                // Get Refinintive mAIndicator data from DB
                $mAIndicatorDataFromDB = $this->getDataFromDB(RefMostActiveEnum::TOPGAINER->value);

                // If the data is fresh, initialize from the database
                if ($this->isFreshData($mAIndicatorDataFromDB)) {
                    $this->loadSwooleTableFromDB(RefMostActiveEnum::TOPGAINER->value, $mAIndicatorDataFromDB);
                } else {
                    var_dump("There is older data than five minutes");
                    // Get companies details from DB
                    $companyDetail = $this->getCompaniesFromDB();
                    $isProcessedRefMAIndicatorData = $this->processData($companyDetail, true, RefMostActiveEnum::TOPGAINER->value);

                    // If Refinitive data is not processed, load old data from the DB because the Refinitive API is not returning data at this time
                    if(!$isProcessedRefMAIndicatorData) {
                        var_dump('Load old Refinitive data from the DB because the Refinitive API is not returning data at this time');
                        $this->loadSwooleTableFromDB(RefMostActiveEnum::TOPGAINER->value, $mAIndicatorDataFromDB);
                         // Load Job run at into swoole table
                        $this->saveRefJobRunAtIntoSwooleTable($mAIndicatorDataFromDB[0]['latest_update']);
                    }

                }
            } else {
                var_dump("There is no Refinitive data in DB");
                // Get companies details from DB
                $companyDetail = $this->getCompaniesFromDB();
                $isProcessedRefMAIndicatorData = $this->processData($companyDetail, false, RefMostActiveEnum::TOPGAINER->value);

                if(!$isProcessedRefMAIndicatorData) {
                    var_dump('Refinitive API is not returning data at this time');
                }
            }

             ////////////////// Code will be removed end /////////

            // Schedule of Most Active Data Fetching
            swTimer::tick(config('app_config.most_active_refinitive_timespan'), function () use ($companyDetail) {
                $this->initRef($companyDetail);
            });

    }

    public function initRef(mixed $companyDetail)
    {
        if(is_null($companyDetail)) {
            $companyDetail = $this->getCompaniesFromDB();
        }
        // Fetch data from Refinitiv API
        $responses = $this->fetchRefData($companyDetail);
        // Handle refinitive responses
        $refMAIndicatorData = $this->handleRefResponses($responses, $companyDetail);

        if (count($refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value]) > 0) {
            // Handle the Refinitive Top Gainer Module
            $this->refIndicatorModuleHandle($refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value], RefMostActiveEnum::TOPGAINER->value, self::TOPGAINERCOLUMN);
        } else {
            // Data not received from Refinitiv
            var_dump('Data not received from Refinitiv Pricing Snapshot API');
        }

        // Save refintive most active logs into DB table
        if (count($refMAIndicatorData[self::ERRORLOG]) > 0) {
            $this->saveLogsIntoDBTable($refMAIndicatorData[self::ERRORLOG]);
        }
    }

    public function refIndicatorModuleHandle(array $refMAIndicatorData, string $tableName, string $column)
    {
        $previousMAIndicatorData = [];

        $refSwooleTableData = SwooleTableFactory::getTableData($tableName);

        if (count($refSwooleTableData) > 0) {
            var_dump('Data fetched from swoole table ' . $tableName);
            $previousMAIndicatorData = $refSwooleTableData;
        }

        // Check data exist into swoole table
        if (count($previousMAIndicatorData) < 1) {
            $previousMAIndicatorData = $this->fetchTableDataFromDB($tableName);
            var_dump('Data fetched from DB table ' . $tableName);
        }

        // Check previous data exists
        if (count($previousMAIndicatorData) > 0) {
            // Compare with existing data
            $differentMAIndicatorData = $this->compare($previousMAIndicatorData, $refMAIndicatorData, $column);

            if(count($differentMAIndicatorData) > 0) {
                // Fetch data from swoole table ma_indicator_job_runs_at
                $mAIndicatorJobRunsAtData = SwooleTableFactory::getTableData(tableName: 'ma_indicator_job_runs_at');
                $mAIndicatorJobRunsAt = isset($mAIndicatorJobRunsAtData[0]['job_run_at'])
                ? $mAIndicatorJobRunsAtData[0]['job_run_at']
                : null;
                // Broadcast the changed data
                $this->broadCastIndicatorData($differentMAIndicatorData, $mAIndicatorJobRunsAt);
            }
        } else {
            var_dump('No changed data for broadcasting data of ' . $tableName);
            $differentMAIndicatorData = $refMAIndicatorData;
        }

        // Check there is changed data then save into DB and swoole table
        if (count($differentMAIndicatorData) > 0) {
            // Save into swoole table
            $this->saveIntoSwooleTable($differentMAIndicatorData, $tableName);
            // Save into DB Table
            $this->saveIntoDBTable($refMAIndicatorData, $tableName, true);
            // Save Job run at into swoole table
            $this->saveRefJobRunAtIntoSwooleTable($refMAIndicatorData[0]['latest_update']);
        }
    }

    public function isFreshData($mAIndicatorDataFromDB)
    {
        $isFreshDBData = false;
        // Parse the latest_update timestamp
        $latestUpdate = Carbon::parse($mAIndicatorDataFromDB[0]['latest_update']);
        // Check if the latest update is more than 5 minutes old
        if ($latestUpdate->diffInMinutes(Carbon::now()) < 5) {
            $isFreshDBData = true;
        }

        return $isFreshDBData;
    }

    public function isFreshRefData($indicatorDataFromDB)
    {
        $isFreshDBData = false;
        // Parse the latest_update timestamp
        $latestUpdate = Carbon::parse($indicatorDataFromDB[0]['updated_at']);
        // Check if the latest update is more than 5 minutes old
        if ($latestUpdate->diffInMilliseconds(Carbon::now()) < config('app_config.most_active_refinitive_timespan')) {
            $isFreshDBData = true;
        }

        return $isFreshDBData;
    }

    public function getDataFromDB($tableName)
    {
        $dbQuery = "SELECT refTable.*, c.name AS en_long_name, c.sp_comp_id, c.short_name AS en_short_name, c.symbol,
        c.isin_code, c.created_at, c.arabic_name AS ar_long_name, c.arabic_short_name AS ar_short_name, c.ric
        FROM $tableName refTable JOIN companies c ON refTable.company_id = c.id";
        return $this->dbFacade->query($dbQuery, $this->objDbPool);
    }
    public function getRefDataFromDB($tableName)
    {
        $dbQuery = "SELECT refTable.*, c.name AS en_long_name, c.sp_comp_id, c.short_name AS en_short_name, c.symbol,
        c.isin_code, c.created_at, c.arabic_name AS ar_long_name, c.arabic_short_name AS ar_short_name, c.ric
        FROM $tableName refTable JOIN companies c ON refTable.company_id = c.id
         ORDER BY refTable.updated_at DESC";
        return $this->dbFacade->query($dbQuery, $this->objDbPool);
    }

    public function loadSwooleTableFromDB($tableName, $mAIndicatorDBData)
    {
        var_dump("Record is within the last 5 minutes. Data prepared.");
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($mAIndicatorDBData as $mAIndicatorDBRec) {
            if ($tableName == RefMostActiveEnum::TOPGAINER->value) {
                $data = [
                    'calculated_value' => (float)$mAIndicatorDBRec['calculated_value'],
                    'latest_value' => (float)$mAIndicatorDBRec['latest_value'],
                    'latest_update' => $mAIndicatorDBRec['latest_update'],
                    'company_id' => $mAIndicatorDBRec['company_id'],

                    'en_long_name' =>  $mAIndicatorDBRec['en_long_name'],
                    'sp_comp_id' =>  $mAIndicatorDBRec['sp_comp_id'],
                    'en_short_name' =>  $mAIndicatorDBRec['en_short_name'],
                    'symbol' =>  $mAIndicatorDBRec['symbol'],
                    'isin_code' =>  $mAIndicatorDBRec['isin_code'],
                    'created_at' =>  $mAIndicatorDBRec['created_at'],
                    'ar_long_name' =>  $mAIndicatorDBRec['ar_long_name'],
                    'ar_short_name' =>  $mAIndicatorDBRec['ar_short_name'],
                    'ric' =>  $mAIndicatorDBRec['ric'],
                ];

                $table->set($data['company_id'], $data);
            }
        }
    }

    public function loadSwooleTableWithRefDataFromDB($tableName, $mAIndicatorDBData)
    {
        var_dump("Record is within the last 5 minutes. Data prepared.");
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($mAIndicatorDBData as $mAIndicatorDBRec) {
            if ($tableName == RefMostActiveEnum::TOPGAINER->value) {
                $data = [
                    'cf_high' => (float)$mAIndicatorDBRec['cf_high'],
                    'cf_last' => (float)$mAIndicatorDBRec['cf_last'],
                    'cf_low' => (float)$mAIndicatorDBRec['cf_low'],
                    'cf_volume' => (float)$mAIndicatorDBRec['cf_volume'],
                    'high_1' => (float)$mAIndicatorDBRec['high_1'],
                    'hst_close' => (float)$mAIndicatorDBRec['hst_close'],
                    'low_1' => (float)$mAIndicatorDBRec['low_1'],
                    'netchng_1' => (float)$mAIndicatorDBRec['netchng_1'],
                    'num_moves' => (float)$mAIndicatorDBRec['num_moves'],
                    'open_prc' => (float)$mAIndicatorDBRec['open_prc'],
                    'pctchng' => (float)$mAIndicatorDBRec['pctchng'],
                    'trdprc_1' => (float)$mAIndicatorDBRec['trdprc_1'],
                    'turnover' => (float)$mAIndicatorDBRec['turnover'],
                    'yrhigh' => (float)$mAIndicatorDBRec['yrhigh'],
                    'yrlow' => (float)$mAIndicatorDBRec['yrlow'],
                    'yr_pctch' => (float)$mAIndicatorDBRec['yr_pctch'],
                    'cf_close' => (float)$mAIndicatorDBRec['cf_close'],
                    'bid' => (float)$mAIndicatorDBRec['bid'],
                    'ask' => (float)$mAIndicatorDBRec['ask'],
                    'asksize' => (float)$mAIndicatorDBRec['asksize'],
                    'bidsize' => (float)$mAIndicatorDBRec['bidsize'],

                    'company_id' => $mAIndicatorDBRec['company_id'],
                    'en_long_name' =>  $mAIndicatorDBRec['en_long_name'],
                    'sp_comp_id' =>  $mAIndicatorDBRec['sp_comp_id'],
                    'en_short_name' =>  $mAIndicatorDBRec['en_short_name'],
                    'symbol' =>  $mAIndicatorDBRec['symbol'],
                    'isin_code' =>  $mAIndicatorDBRec['isin_code'],
                    'created_at' =>  $mAIndicatorDBRec['created_at'],
                    'updated_at' =>  $mAIndicatorDBRec['updated_at'],
                    'ar_long_name' =>  $mAIndicatorDBRec['ar_long_name'],
                    'ar_short_name' =>  $mAIndicatorDBRec['ar_short_name'],
                    'ric' =>  $mAIndicatorDBRec['ric'],
                    'logo' =>  $mAIndicatorDBRec['logo'],
                    'market_id' =>  $mAIndicatorDBRec['market_id'],
                    'market_name' =>  $mAIndicatorDBRec['market_name'],
                ];

                $table->set($data['company_id'], $data);
            }
        }
    }

    public function loadDatastoresFromRef($refMAIndicatorData, $tableName, $column, $isTruncate)
    {
        var_dump("Initialize fresh data from Refinitive for top gainers");
        // Save into swoole table
        $this->saveIntoSwooleTable($refMAIndicatorData, $tableName);
        // Save into DB Table
        $this->saveIntoDBTable($refMAIndicatorData, $tableName, $isTruncate);
    }
    /**
     * Most active data fetching
     *
     * @param  mixed  $companyDetail
     * @return void
     */

    public function fetchRefData(mixed $companyDetail)
    {
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('app_config.refinitive_pricing_snapshot_url'));
        $responses = $service->handle($companyDetail, $this->fields);
        unset($service);

        return $responses;
    }

    public function handleRefSnapshotResponses($responses, $companyDetail)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [];
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                var_dump('Missing "Fields" key in pricing snapshot api response: ' . json_encode($res));
                continue;
            }

            $d = [];
            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = (float) ($res['Fields'][$field] ?? 0);
                $field = strtolower($field);

                $d[$field] = $value;
            }

            if(!empty($d)) {
                $d = $this->appendCompanyDetails($d, $company, $date);
                array_push($refSnapshotIndicatorData, $d);
            }

        }

        return $refSnapshotIndicatorData;
    }

    public function handleRefSnapshotResponsesWithOldData($responses, $companyDetail)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [
            RefMostActiveEnum::TOPGAINER->value => [],
            self::ALLCHANGEDRECORDS => [],
            self::ALLRECORDS => [],
            self::ISREFDATA => false,
        ];

        $isChangedTopGainer = false;
        $isChangedData = false;
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                var_dump('Missing "Fields" key in pricing snapshot api response: ' . json_encode($res));
                continue;
            }

            $d = [];
            $isChangedData = false;
            $isChangedTopGainer = false;

            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = (float) ($res['Fields'][$field] ?? 0);
                $field = strtolower($field);

                if (
                    !empty($value)
                    &&
                    $value != $company[$field]
                ) {
                    $d[$field] = $value;
                    $isChangedData = true;
                    $refSnapshotIndicatorData[self::ISREFDATA] = true;

                    if ($field === 'pctchng') {
                        $isChangedTopGainer = true;
                    }
                } else {
                    $d[$field] = (float) isset($company[$field]) ?? null;
                }
            }

            $d = $this->appendCompanyDetails($d, $company, $date);
            $refSnapshotIndicatorData[self::ALLRECORDS][] = $d;

            if ($isChangedData) {
                if ($isChangedTopGainer) {

                    $refSnapshotIndicatorData[RefMostActiveEnum::TOPGAINER->value][] = [
                        'trdprc_1' => $d['trdprc_1'],
                        'pctchng' => $d['pctchng'],
                        'created_at' => $d['created_at'],
                        'en_long_name' => $d['en_long_name'],
                        'sp_comp_id' => $d['sp_comp_id'],
                        'en_short_name' => $d['en_short_name'],
                        'symbol' => $d['symbol'],
                        'isin_code' => $d['isin_code'],
                        'ar_long_name' => $d['ar_long_name'],
                        'ar_short_name' => $d['ar_short_name'],
                        'ric' => $d['ric']
                    ];
                }

                $refSnapshotIndicatorData[self::ALLCHANGEDRECORDS][] = $d;
            }
        }

        return $refSnapshotIndicatorData;
    }

    public function appendCompanyDetails($data, $company, $date)
    {
        return array_merge($data, [
            'ric' => $company['ric'],
            'company_id' => $company['id'],
            'created_at' => $date,
            'updated_at' => $date,
            'en_long_name' => $company['name'],
            'sp_comp_id' => $company['sp_comp_id'],
            'en_short_name' => $company['short_name'],
            'symbol' => $company['symbol'],
            'isin_code' => $company['isin_code'],
            'ar_long_name' => $company['arabic_name'],
            'ar_short_name' => $company['arabic_short_name'],
            'logo' => $company['logo'],
            'market_id' => $company['market_id'],
            'market_name' => $company['market_name']
        ]);
    }

    public function handleRefResponses($responses, $companyDetail)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');
        $dateTimeStamp = Carbon::now();
        $refMAIndicatorData = [];
        $refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value] = [];
        $refMAIndicatorData[RefMostActiveEnum::MOSTACTIVEVALUE->value] = [];
        $refMAIndicatorData[RefMostActiveEnum::DONEDEALCOUNT->value] = [];
        $refMAIndicatorData[RefMostActiveEnum::FREQUENTLYTRADEDSTOCK->value] = [];
        $refMAIndicatorData[self::ERRORLOG] = [];
        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!empty($res) && isset($res['Fields']["TRDPRC_1"]) && isset($res['Fields']["PCTCHNG"])) { // Top gainers

                $data = [
                    'calculated_value' => $res['Fields']["PCTCHNG"],
                    'latest_value' => $res['Fields']["TRDPRC_1"],
                    'latest_update' => $date,
                    'company_id' => $company['id'],

                    'en_long_name' =>  $company['name'],
                    'sp_comp_id' =>  $company['sp_comp_id'],
                    'en_short_name' =>  $company['short_name'],
                    'symbol' =>  $company['symbol'],
                    'isin_code' =>  $company['isin_code'],
                    'created_at' =>  $company['created_at'],
                    'ar_long_name' =>  $company['arabic_name'],
                    'ar_short_name' =>  $company['arabic_short_name'],
                    'ric' =>  $company['ric'],
                    'logo' =>  $company['logo'],
                    'market_id' =>  $company['market_id'],
                    'market_name' =>  $company['market_name'],
                ];
                $refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value][] = $data;
            } else if (empty($res) || !isset($res['Fields']["TRDPRC_1"]) || !isset($res['Fields']["PCTCHNG"])) {
                $refMAIndicatorData[self::ERRORLOG][] = [
                    'ric' =>  $company['ric'],
                    'type' => RefMostActiveEnum::TOPGAINER->value,
                    'created_at' => $dateTimeStamp,
                    'updated_at' => $dateTimeStamp,
                ];
            }

            if (!empty($res) && isset($res['Fields']["TURNOVER"])) {  // Most Active Value
                $data = [
                    'turnover' => $this->formatValue($res['Fields']["TURNOVER"]),
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,
                ];
                $refMAIndicatorData[RefMostActiveEnum::MOSTACTIVEVALUE->value][] = $data;
            }

            if (!empty($res) && isset($res['Fields']["NUM_MOVES"])) {  // Deal Done count
                $data = [
                    'count' => $res['Fields']["NUM_MOVES"],
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,
                ];
                $refMAIndicatorData[RefMostActiveEnum::DONEDEALCOUNT->value][] = $data;
            }

            if (!empty($res) && isset($res['Fields']["CF_VOLUME"])) {  // Traded Stock
                $data = [
                    'traded_volume' => $this->formatValue($res['Fields']["CF_VOLUME"]),
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,
                ];
                $refMAIndicatorData[RefMostActiveEnum::FREQUENTLYTRADEDSTOCK->value][] = $data;
            }
        }

        return $refMAIndicatorData;
    }

    /**
     * Format the value to two decimal places and return as a float.
     *
     * @param  float  $value
     * @return float
     */
    protected function formatValue($value): float
    {
        return round((float) $value, 6);
    }

    public function fetchTableDataFromDB(string $table)
    {
        $dbQuery = "SELECT * FROM " . $table;
        return $this->dbFacade->query($dbQuery, $this->objDbPool);
    }

    public function broadCastIndicatorData(array $differentMAIndicatorData, mixed $mAIndicatorJobRunsAt)
    {
        // Broadcast the Top-Gainers Data to topic "top-gainers"
        $topGainersData = [
            'topic' => 'top-gainers',
            'message_data' => [
                'ref_top_gainers' => $differentMAIndicatorData,
                'job_runs_at' => $mAIndicatorJobRunsAt,
            ],
        ];

        $jsonData = json_encode($topGainersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonData == false) {
            echo "JSON encoding error: " . json_last_error_msg() . PHP_EOL;
        } else {
            go(function () use ($jsonData) {
                for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
                    $this->server->sendMessage($jsonData, $worker_id);
                }
            });
        }
    }

    public function saveIntoSwooleTable(array $mAIndicatorData, string $tableName)
    {
        var_dump('Save data into Swoole table ' . $tableName);
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($mAIndicatorData as $data) {
                $table->set($data['company_id'], $data);
        }
    }

    public function saveIntoDBTable(array $mAIndicatorData, string $tableName, bool $isTruncate )
    {
        var_dump('Save data into DB table ' . $tableName);
        go(function () use ($mAIndicatorData, $tableName, $isTruncate) {
            $dbQuery ="";
            if($isTruncate) {
                $dbQuery = 'TRUNCATE TABLE ONLY public."' . $tableName . '" RESTART IDENTITY RESTRICT;';
            }
            $dbQuery .= $this->makeInsertQuery($tableName, $mAIndicatorData);
            $this->dbFacade->query($dbQuery, $this->objDbPool, null, true, true, $tableName);
        });
    }

    public function saveIntoRefDataCompnayDBTable(array $mAIndicatorData, string $tableName )
    {
        var_dump('Save data into DB table ' . $tableName);
        go(function () use ($mAIndicatorData, $tableName) {
            $dbQuery ="";
            $dbQuery .= $this->makeInsertQuery($tableName, $mAIndicatorData);
            $this->dbFacade->query($dbQuery, $this->objDbPool, null, true, true, $tableName);
        });
    }

    public function UpdateIntoDBTable(array $mAIndicatorData, string $tableName )
    {
        var_dump('Update data into DB table '. $tableName);
        go(function () use ($mAIndicatorData, $tableName) {
            foreach ($mAIndicatorData as $mAIndicator) {
                $dbQuery = "UPDATE $tableName
                            SET
                                cf_high = " . $mAIndicator['cf_high'] . ",
                                cf_last = " . $mAIndicator['cf_last'] . ",
                                cf_low = " . $mAIndicator['cf_low'] . ",
                                cf_volume = " . $mAIndicator['cf_volume'] . ",
                                high_1 = " . $mAIndicator['high_1'] . ",
                                hst_close = " . $mAIndicator['hst_close'] . ",
                                low_1 = " . $mAIndicator['low_1'] . ",
                                netchng_1 = " . $mAIndicator['netchng_1'] . ",
                                num_moves = " . $mAIndicator['num_moves'] . ",
                                open_prc = " . $mAIndicator['open_prc'] . ",
                                pctchng = " . $mAIndicator['pctchng'] . ",
                                trdprc_1 = " . $mAIndicator['trdprc_1'] . ",
                                turnover = " . $mAIndicator['turnover'] . ",
                                yrhigh = " . $mAIndicator['yrhigh'] . ",
                                yrlow = " . $mAIndicator['yrlow'] . ",
                                yr_pctch = " . $mAIndicator['yr_pctch'] . ",
                                cf_close = " . $mAIndicator['cf_close'] . ",
                                bid = " . $mAIndicator['bid'] . ",
                                ask = " . $mAIndicator['ask'] . ",
                                asksize = " . $mAIndicator['asksize'] . ",
                                updated_at = '" . $mAIndicator['updated_at'] . "'
                            WHERE company_id = " . $mAIndicator['company_id'];

                $this->dbFacade->query($dbQuery, $this->objDbPool);
            }
        });

    }

    public function saveLogsIntoDBTable(array $mAIndicatorLogs)
    {
        var_dump('Save logs into DB table ref_most_active_logs');
        go(function () use ($mAIndicatorLogs) {
            $values = [];
            foreach ($mAIndicatorLogs as $mAIndicatorlog) {
                // Collect each set of values into the array
                $values[] = "('" . $mAIndicatorlog['ric'] . "', '" . $mAIndicatorlog['type'] . "', '" . $mAIndicatorlog['created_at'] . "', '" . $mAIndicatorlog['updated_at'] . "')";
            }
            $dbQuery = "INSERT INTO ref_most_active_logs (ric, type, created_at, updated_at)
            VALUES " . implode(", ", $values);

            $this->dbFacade->query($dbQuery, $this->objDbPool);
        });
    }

    public function saveLogsRefDataIntoDBTable(array $mAIndicatorLogs)
    {
        var_dump('Save logs into DB table ref_most_active_logs');
        go(function () use ($mAIndicatorLogs) {
            $values = [];
            foreach ($mAIndicatorLogs as $mAIndicatorlog) {
                // Collect each set of values into the array
                $values[] = "('" . $mAIndicatorlog['ric'] . "', 'company', '" . $mAIndicatorlog['created_at'] . "', '" . $mAIndicatorlog['updated_at'] . "')";
            }
            $dbQuery = "INSERT INTO ref_most_active_logs (ric, type, created_at, updated_at)
            VALUES " . implode(", ", $values);

            $this->dbFacade->query($dbQuery, $this->objDbPool);
        });
    }

    public function saveRefSnapshotDataIntoDBTable(array $mAIndicatorData, $tableName)
    {
        var_dump('Save Refinitive snapshot data into DB table');
        go(function () use ($mAIndicatorData, $tableName) {
            $values = [];
            foreach ($mAIndicatorData as $mAIndicator) {
                // Collect each set of values into the array
                $values[] = "(
                    " . $mAIndicator['company_id'] . ",
                    " . $mAIndicator['cf_high'] . ",
                    " . $mAIndicator['cf_last'] . ",
                    " . $mAIndicator['cf_low'] . ",
                    " . $mAIndicator['cf_volume'] . ",
                    " . $mAIndicator['high_1'] . ",
                    " . $mAIndicator['hst_close'] . ",
                    " . $mAIndicator['low_1'] . ",
                    " . $mAIndicator['netchng_1'] . ",
                    " . $mAIndicator['num_moves'] . ",
                    " . $mAIndicator['open_prc'] . ",
                    " . $mAIndicator['pctchng'] . ",
                    " . $mAIndicator['trdprc_1'] . ",
                    " . $mAIndicator['turnover'] . ",
                    " . $mAIndicator['yrhigh'] . ",
                    " . $mAIndicator['yrlow'] . ",
                    " . $mAIndicator['yr_pctch'] . ",
                    " . $mAIndicator['cf_close'] . ",
                    " . $mAIndicator['bid'] . ",
                    " . $mAIndicator['ask'] . ",
                    " . $mAIndicator['asksize'] . ",
                    " . $mAIndicator['bidsize'] . ",
                    '" . $mAIndicator['created_at'] . "',
                    '" . $mAIndicator['updated_at'] . "'
                )";
            }

            $dbQuery = "INSERT INTO $tableName (
                company_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close,
                low_1, netchng_1, num_moves, open_prc, pctchng, trdprc_1, turnover,
                yrhigh, yrlow, yr_pctch, cf_close, bid, ask, asksize, bidsize, created_at, updated_at
            ) VALUES " . implode(", ", $values);

            $this->dbFacade->query($dbQuery, $this->objDbPool);
        });
    }

    /**
     * Compare two arrays of most active values to identify differences and new entries.
     *
     * @param array $previousMAIndicatorValues Array of associative arrays representing previous most active values.
     * @param array $mAIndicatorValues Array of associative arrays representing current most active values.
     * @param string $column string has name of column.
     *
     * @return array Array of associative arrays representing most active values that are either different or newly added.
     */
    public function compare(array $previousMAIndicatorValues, array $mAIndicatorValues, string $column): array
    {
        $differentMAIndicatorValues = [];
        $compareDataBerrier = Barrier::make();
        foreach ($previousMAIndicatorValues as $previousMAIndicatorValue) {
            go(function () use ($previousMAIndicatorValue, $mAIndicatorValues, $column, &$differentMAIndicatorValues, $compareDataBerrier) {
                foreach ($mAIndicatorValues as $mAIndicatorValue) {
                    if ($previousMAIndicatorValue['company_id'] === $mAIndicatorValue['company_id']) {
                        if ($this->formatValue($previousMAIndicatorValue[$column]) != $this->formatValue($mAIndicatorValue[$column])) {
                            $differentMAIndicatorValues[] = $mAIndicatorValue;
                        }
                    }
                }
            });
        }

        foreach ($mAIndicatorValues as $mAIndicatorValue) {
            go(function () use ($previousMAIndicatorValues, $mAIndicatorValue, &$differentMAIndicatorValues, $compareDataBerrier) {
                $found = false;
                foreach ($previousMAIndicatorValues as $previousMAIndicatorValue) {
                    if ($previousMAIndicatorValue['company_id'] === $mAIndicatorValue['company_id']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $differentMAIndicatorValues[] = $mAIndicatorValue;
                }
            });
        }

        Barrier::wait($compareDataBerrier);
        var_dump('comparing refinitive data');
        return $differentMAIndicatorValues;
    }

    public function makeInsertQuery(string $tableName, array $mAIndicatorData)
    {
        $dbQuery = "";
        $values = [];

        switch ($tableName) {
            case RefMostActiveEnum::TOPGAINER->value === $tableName:
                foreach ($mAIndicatorData as $mAIndicator) {
                    // Collect each set of values into the array
                    $values[] = "('" . $mAIndicator['calculated_value'] . "', '" . $mAIndicator['latest_value'] . "', '" . $mAIndicator['latest_update'] . "', '" . $mAIndicator['company_id'] . "')";
                }
                $dbQuery = "INSERT INTO " . $tableName . " (calculated_value, latest_value, latest_update, company_id)
                VALUES " . implode(", ", $values);
                break;
            default:
                break;
        }

        return $dbQuery;
    }

    public function getCompaniesFromDB()
    {
        $dbQuery = "SELECT ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, c.created_at, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        WHERE c.ric IS NOT NULL
        AND c.ric NOT LIKE '%^%'
        AND c.ric ~ '^[0-9a-zA-Z\\.]+$'";

        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
        $results = $this->dbFacade->query($dbQuery, $this->objDbPool);

        // Process the results: create an associative array with 'ric' as the key and 'id' as the value
        $companyDetail = [];
        foreach ($results as $row) {
            $companyDetail[$row['ric']] = $row;
        }

        return $companyDetail;
    }

    public function getCompaniesWithRefDataFromDB()
    {
        $dbQuery = "SELECT r.*, ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, c.created_at, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        LEFT JOIN ref_data_snapshot_companies As r On c.id = r.company_id
        WHERE c.ric IS NOT NULL
        AND c.ric NOT LIKE '%^%'
        AND c.ric ~ '^[0-9a-zA-Z\\.]+$'";

        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
        $results = $this->dbFacade->query($dbQuery, $this->objDbPool);

        // Process the results: create an associative array with 'ric' as the key and 'id' as the value
        $companyDetail = [];
        foreach ($results as $row) {
            $companyDetail[$row['ric']] = $row;
        }

        return $companyDetail;
    }

    public function getDataCountFromDB(string $tableName)
    {
        $dbQuery = "SELECT count(*)  FROM " . $tableName;
        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
        $refCountInDB = $this->dbFacade->query($dbQuery, $this->objDbPool);

        return $refCountInDB;
    }

    public function processData($companyDetail, $isStale, $tableName)
    {
        $isProcessedRefMAIndicatorData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($companyDetail);

        // Handle Refinitive responses
        $refMAIndicatorData = $this->handleRefResponses($responses, $companyDetail);

        // Initialize fresh data from Refinitive for top gainers
        if (count($refMAIndicatorData[$tableName]) > 0) {
            $this->loadDatastoresFromRef(
                $refMAIndicatorData[$tableName],
                $tableName,
                self::TOPGAINERCOLUMN,
                $isStale
            );

            $isProcessedRefMAIndicatorData = true;
             // Load Job run at into swoole table
            $this->saveRefJobRunAtIntoSwooleTable($refMAIndicatorData[$tableName][0]['latest_update']);
        }

        // Save Refinitive most active logs into DB table
        if (count($refMAIndicatorData[self::ERRORLOG]) > 0) {
            $this->saveLogsIntoDBTable($refMAIndicatorData[self::ERRORLOG]);
        }

        return $isProcessedRefMAIndicatorData;
    }

    public function processRefData($companyDetail, $tableName, $isWithOldData = null)
    {
        $isProcessedRefMAIndicatorData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($companyDetail);

        // Handle Refinitive responses
        if($isWithOldData) {
            $refMAIndicatorData = $this->handleRefSnapshotResponsesWithOldData($responses, $companyDetail);

            if (count($refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value]) > 0) {
                // Broadcasting changed data
                $mAIndicatorJobRunsAtData = SwooleTableFactory::getTableData(tableName: 'ma_indicator_job_runs_at');
                $mAIndicatorJobRunsAt = isset($mAIndicatorJobRunsAtData[0]['job_run_at'])
                ? $mAIndicatorJobRunsAtData[0]['job_run_at']
                : null;
                $this->broadCastIndicatorData($refMAIndicatorData[RefMostActiveEnum::TOPGAINER->value], $mAIndicatorJobRunsAt);
            }

            if(count($refMAIndicatorData[self::ALLRECORDS]) > 0 ) {
                // Load into the swoole Table
                $this->saveIntoSwooleTable($refMAIndicatorData[self::ALLRECORDS], $tableName);
                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($refMAIndicatorData[self::ALLRECORDS][0]['created_at']);
            }

            // Update Into the Database
            if(count($refMAIndicatorData[self::ALLCHANGEDRECORDS]) > 0) {
                $this->UpdateIntoDBTable($refMAIndicatorData[self::ALLCHANGEDRECORDS], $tableName);
            }

            // Is Refinitive return data
            $isProcessedRefMAIndicatorData = $refMAIndicatorData[self::ISREFDATA];

        } else {
            $refMAIndicatorData = $this->handleRefSnapshotResponses($responses, $companyDetail);

            // Initialize fresh data from Refinitive for top gainers
            if (count($refMAIndicatorData) > 0) {
                var_dump("Initialize fresh data from Refinitive for top gainers");
                // Save into swoole table
                $this->saveIntoSwooleTable($refMAIndicatorData, $tableName);
                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refMAIndicatorData, self::REFSNAPSHOTCOMPANIES);

                $isProcessedRefMAIndicatorData = true;
                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($refMAIndicatorData[0]['created_at']);
            }
        }

        // // Save Refinitive most active logs into DB table
        // if (count($refMAIndicatorData[self::ERRORLOG]) > 0) {
        //     $this->loadDatastoresFromRef($refMAIndicatorData[self::ERRORLOG]);
        // }

        return $isProcessedRefMAIndicatorData;
    }

    public function saveRefJobRunAtIntoSwooleTable($jobRunAt) {
        var_dump('Save data into Swoole table ma_indicator_job_runs_at');
        $data = ['job_run_at' => $jobRunAt];
        $table = SwooleTableFactory::getTable('ma_indicator_job_runs_at');
        $table->set(0, $data);
    }
}
