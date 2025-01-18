<?php

namespace App\Services;

use DB\DBConnectionPool;

use Swoole\Timer as swTimer;
use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Throwable;
use Swoole\Coroutine\Channel;

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
    protected $refTimeSpan;
    protected $refSeconds;

    const ERRORLOG = 'error_logs';
    const ALLCHANGEDRECORDS = 'all_changed_records';
    const ALLRECORDS = 'all_records';
    const REFSNAPSHOTCOMPANIES = 'ref_data_snapshot_companies';
    const ISREFDATA = 'is_ref_data';
    const ALLCHANGEDINDICATORS = 'all_changed_indicators';

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
        $this->fields = config('ref_config.ref_fields');
        $this->refTimeSpan = config('app_config.most_active_data_fetching_timespan');
        $this->refSeconds =  $this->refTimeSpan  / 1000;
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        $companyDetail = null;
        $dataExistInDB = false;
        $dataInitCase = true;

        // Aggregate query to get the count from the Refinitive table
        $dataExistInDB = $this->getDataCountFromDB(self::REFSNAPSHOTCOMPANIES);

        if ($dataExistInDB) { // Case: The websocket service not running for the first time in its entirety
            // Get only companies which has Ref Data from DB.
            $companyDetailWithRefData = $this->fetchOnlyRefDataCompaniesWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES);

            // If the data is fresh, initialize from the database
            if ($this->isFreshRefData($companyDetailWithRefData)) {
                $this->loadSwooleTableWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES, $companyDetailWithRefData);
                var_dump("Data is loaded into the swoole");
            } else {
                var_dump("There is older data than $this->refSeconds seconds");
                // Get all companies with Ref Data from the database for calculating change or delta, excluding those with a caret symbol ('^') in their RIC.
                $companyDetailWithRefData = $this->getAllCompaniesWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES);

                // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                $isProcessedRefIndicatorData = $this->processRefData($companyDetailWithRefData, self::REFSNAPSHOTCOMPANIES, $dataExistInDB, $dataInitCase);

                if (!$isProcessedRefIndicatorData) {
                    var_dump('Refinitive API is not returning data at this time');
                }
            }
        } else { // Case: The websocket service is running for the first time in its entirety
            var_dump("There is no Refinitive Company data in DB");
            $companyDetail = $this->getCompaniesFromDB();

            // Fetch data from Refinitive, update in swoole table, and store/update in DB.
            $isProcessedRefIndicatorData = $this->processRefData($companyDetail, self::REFSNAPSHOTCOMPANIES, $dataExistInDB, $dataInitCase);

            if (!$isProcessedRefIndicatorData) {
                var_dump('Refinitive API is not returning data at this time');
            }
        }

        // Schedule of Ref Data Fetching
        swTimer::tick($this->refTimeSpan, function () {
            $this->initRef();
        });
    }

    public function initRef()
    {
        $dataExistInDB = false;
        $dataInitCase = false;
        // Aggregate query to get the count from the Refinitive table
        $dataExistInDB = $this->getDataCountFromDB(self::REFSNAPSHOTCOMPANIES);

        if ($dataExistInDB) {
            $dataExistInDB = true;
            $companyDetailWithRefData = $this->getAllCompaniesWithRefDataFromDB(self::REFSNAPSHOTCOMPANIES);
        } else {
            $dataInitCase = true;
            $companyDetailWithRefData = $this->getCompaniesFromDB();
        }

        $isProcessedRefIndicatorData = $this->processRefData($companyDetailWithRefData, self::REFSNAPSHOTCOMPANIES, $dataExistInDB, $dataInitCase);

        if (!$isProcessedRefIndicatorData) {
            var_dump('Refinitive API is not returning data at this time');
        }
    }

    public function isFreshRefData($indicatorDataFromDB)
    {
        $isFreshDBData = false;
        // Parse the latest_update timestamp
        $latestUpdate = Carbon::parse($indicatorDataFromDB[0]['updated_at']);
        // Check if the latest update is more than 5 minutes old
        if ($latestUpdate->diffInMilliseconds(Carbon::now()) < $this->refTimeSpan) {
            $isFreshDBData = true;
        }

        return $isFreshDBData;
    }

    public function fetchOnlyRefDataCompaniesWithRefDataFromDB($tableName)
    {
        $dbQuery = 'SELECT refTable.*, c.name AS en_long_name, c.sp_comp_id, c.short_name AS en_short_name, c.symbol,
        c.isin_code, c.arabic_name AS ar_long_name, c.arabic_short_name AS ar_short_name, c.ric
        ,logo, parent_id as market_id, m.name as market_name
        FROM '.$tableName.' refTable JOIN companies c ON refTable.company_id = c.id
        INNER JOIN markets As m On c.parent_id = m.id ORDER BY refTable.updated_at DESC';

        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $results = $channel->pop();

        return $results;
    }

    public function loadSwooleTableWithRefDataFromDB($tableName, $mAIndicatorDBData)
    {
        var_dump("Record is within the last $this->refSeconds seconds. Data prepared.");
        $companyInfo = "";
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($mAIndicatorDBData as $mAIndicatorDBRec) {
            $companyInfo = json_encode([
                'company_id' => $mAIndicatorDBRec['company_id'],
                'en_long_name' =>  $mAIndicatorDBRec['en_long_name'],
                'sp_comp_id' =>  $mAIndicatorDBRec['sp_comp_id'],
                'en_short_name' =>  $mAIndicatorDBRec['en_short_name'],
                'symbol' =>  $mAIndicatorDBRec['symbol'],
                'isin_code' =>  $mAIndicatorDBRec['isin_code'],
                'ar_long_name' =>  $mAIndicatorDBRec['ar_long_name'],
                'ar_short_name' =>  $mAIndicatorDBRec['ar_short_name'],
                'ric' =>  $mAIndicatorDBRec['ric'],
                'logo' =>  $mAIndicatorDBRec['logo'],
                'market_id' =>  $mAIndicatorDBRec['market_id'],
                'market_name' =>  $mAIndicatorDBRec['market_name'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
                'sp_comp_id' =>  $mAIndicatorDBRec['sp_comp_id'],
                'isin_code' =>  $mAIndicatorDBRec['isin_code'],
                'ric' =>  $mAIndicatorDBRec['ric'],
                'created_at' =>  $mAIndicatorDBRec['created_at'],
                'updated_at' =>  $mAIndicatorDBRec['updated_at'],
                'company_info' => $companyInfo,
            ];

            $table->set($data['company_id'], $data);
        }
    }

    /**
     * Most active data fetching
     *
     * @param  mixed  $companyDetail
     * @return void
     */

    public function fetchRefData(mixed $companyDetail)
    {
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('ref_config.ref_pricing_snapshot_url'));
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
                // This code will be saved into DB in the Refinement PR
                var_dump('Missing "Fields" key in pricing snapshot api response: ' . json_encode($res));
                continue;
            }

            $d = [];
            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = $res['Fields'][$field] ?? null;
                $field = strtolower($field);

                $d[$field] = $value;
            }

            if(!empty($d)) {
                $d = $this->appendDetails($d, $company, $date);
                array_push($refSnapshotIndicatorData, $d);
            }

        }

        return $refSnapshotIndicatorData;
    }

    public function handleRefSnapshotResponsesWithExistingData($responses, $companyDetail)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [
            self::ALLCHANGEDINDICATORS => [],
            self::ALLCHANGEDRECORDS => [],
            self::ALLRECORDS => [],
            self::ISREFDATA => false,
        ];

        $isChangedData = false;
        $allChangedIndicators = [];
        $indicator = [];
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                // This code will be saved into DB in the Refinement PR
                var_dump('Missing "Fields" key in pricing snapshot api response: ' . json_encode($res));
                continue;
            }

            $d = [];
            $isChangedData = false;

            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = $res['Fields'][$field] ?? null;
                $field = strtolower($field);

                if (
                    !empty($value) &&
                    (!isset($company[$field]) ||
                    $value != $company[$field])
                ) {

                    $d[$field] = $value;
                    $indicator[$field] = $value;

                    // Append detail with every indicator
                    $allChangedIndicators[$field][] = $this->appendDetails($indicator, $company, $date);

                    $isChangedData = true;
                    $refSnapshotIndicatorData[self::ISREFDATA] = true;

                    $indicator = []; // Reset the indicator array

                } else {
                    $d[$field] =  isset($company[$field]) ? (float) $company[$field] : null;
                }
            }

            $d = $this->appendDetails($d, $company, $date);
            $refSnapshotIndicatorData[self::ALLRECORDS][] = $d;

            if ($isChangedData) {
                // All changed Records (Rows)
                $refSnapshotIndicatorData[self::ALLCHANGEDRECORDS][] = $d;
            }
        }

        if(count($allChangedIndicators) > 0) {
            // All changed Indicators (Columns)
            $refSnapshotIndicatorData[self::ALLCHANGEDINDICATORS] = $allChangedIndicators;
        }

        return $refSnapshotIndicatorData;
    }

    public function appendDetails($data, $company, $date)
    {
        $companyInfo = json_encode([
            'ric' => $company['ric'],
            'company_id' => $company['id'],
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array_merge($data, [
            'ric' => $company['ric'],
            'company_id' => $company['id'],
            'isin_code' => $company['isin_code'],
            'sp_comp_id' => $company['sp_comp_id'],
            'created_at' => isset($company['created_at']) ? $company['created_at'] : $date,
            'updated_at' => $date,
            'company_info' => $companyInfo
        ]);
    }

    public function fetchTableDataFromDB(string $table)
    {
        $dbQuery = "SELECT * FROM " . $table;

        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $results = $channel->pop();

        return $results;
    }

    public function broadcastIndicatorsData(array $deltaOfIndicators, mixed $mAIndicatorJobRunsAt)
    {
        // Broadcasting delta indcator-wise
        go(function () use ($deltaOfIndicators, $mAIndicatorJobRunsAt) {
            foreach ($deltaOfIndicators as $key => $indicator) {
                $data = [
                    'topic' => $key,
                    'message_data' => json_encode([
                        $key => $indicator,
                        'job_runs_at' => $mAIndicatorJobRunsAt,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ];

                $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($jsonData == false) {
                    echo "JSON encoding error: " . json_last_error_msg() . PHP_EOL;
                } else {
                    for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
                        $this->server->sendMessage($jsonData, $worker_id);
                    }
                }
            }
        });
    }

    public function saveIntoSwooleTable(array $indicatorsData, string $tableName)
    {
        var_dump('Save data into Swoole table ' . $tableName);
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($indicatorsData as $data) {
                $table->set($data['company_id'], $data);
        }
    }

    public function saveRefSnapshotDataIntoDBTable(array $indicatorsData, $tableName)
    {
        var_dump('Save Refinitive snapshot data into DB table ');
        go(function () use ($indicatorsData, $tableName) {
            $dbQuery = $this->makeRefInsertQuery($tableName, $indicatorsData);

            try {
                $this->dbFacade->query($dbQuery, $this->objDbPool, null, true, true, $tableName);
            } catch (Throwable $e) {
                output($e);
            }
        });
    }

    public function updateRefSnapshotIntoDBTable(array $indicatorsData, string $tableName)
    {
        var_dump('Update data into DB table ' . $tableName);
        go(function () use ($indicatorsData, $tableName) {
            foreach ($indicatorsData as $indicator) {
                $dbQuery = "INSERT INTO $tableName (
                    company_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close, low_1,
                    netchng_1, num_moves, open_prc, pctchng, trdprc_1, turnover, yrhigh, yrlow,
                    yr_pctch, cf_close, bid, ask, asksize, bidsize, created_at, updated_at
                )
                VALUES (
                    " . $indicator['company_id'] . ",
                    " . ($indicator['cf_high'] ?? 'NULL') . ",
                    " . ($indicator['cf_last'] ?? 'NULL') . ",
                    " . ($indicator['cf_low'] ?? 'NULL') . ",
                    " . ($indicator['cf_volume'] ?? 'NULL') . ",
                    " . ($indicator['high_1'] ?? 'NULL') . ",
                    " . ($indicator['hst_close'] ?? 'NULL') . ",
                    " . ($indicator['low_1'] ?? 'NULL') . ",
                    " . ($indicator['netchng_1'] ?? 'NULL') . ",
                    " . ($indicator['num_moves'] ?? 'NULL') . ",
                    " . ($indicator['open_prc'] ?? 'NULL') . ",
                    " . ($indicator['pctchng'] ?? 'NULL') . ",
                    " . ($indicator['trdprc_1'] ?? 'NULL') . ",
                    " . ($indicator['turnover'] ?? 'NULL') . ",
                    " . ($indicator['yrhigh'] ?? 'NULL') . ",
                    " . ($indicator['yrlow'] ?? 'NULL') . ",
                    " . ($indicator['yr_pctch'] ?? 'NULL') . ",
                    " . ($indicator['cf_close'] ?? 'NULL') . ",
                    " . ($indicator['bid'] ?? 'NULL') . ",
                    " . ($indicator['ask'] ?? 'NULL') . ",
                    " . ($indicator['asksize'] ?? 'NULL') . ",
                    " . ($indicator['bidsize'] ?? 'NULL') . ",
                    '" . $indicator['created_at'] . "',
                    '" . $indicator['updated_at'] . "'
                )
                ON CONFLICT (company_id) DO UPDATE SET
                    cf_high = EXCLUDED.cf_high,
                    cf_last = EXCLUDED.cf_last,
                    cf_low = EXCLUDED.cf_low,
                    cf_volume = EXCLUDED.cf_volume,
                    high_1 = EXCLUDED.high_1,
                    hst_close = EXCLUDED.hst_close,
                    low_1 = EXCLUDED.low_1,
                    netchng_1 = EXCLUDED.netchng_1,
                    num_moves = EXCLUDED.num_moves,
                    open_prc = EXCLUDED.open_prc,
                    pctchng = EXCLUDED.pctchng,
                    trdprc_1 = EXCLUDED.trdprc_1,
                    turnover = EXCLUDED.turnover,
                    yrhigh = EXCLUDED.yrhigh,
                    yrlow = EXCLUDED.yrlow,
                    yr_pctch = EXCLUDED.yr_pctch,
                    cf_close = EXCLUDED.cf_close,
                    bid = EXCLUDED.bid,
                    ask = EXCLUDED.ask,
                    asksize = EXCLUDED.asksize,
                    bidsize = EXCLUDED.bidsize,
                    created_at = EXCLUDED.created_at,
                    updated_at = EXCLUDED.updated_at;
                ";
                go(function () use ($dbQuery) {
                    try {
                        $this->dbFacade->query($dbQuery, $this->objDbPool);
                    } catch (Throwable $e) {
                        output($e);
                    }
                });
            }
        });
    }

    public function makeRefInsertQuery(string $tableName, array $indicatorsData)
    {
        $dbQuery = "";
        $values = [];

        switch ($tableName) {
            case self::REFSNAPSHOTCOMPANIES == $tableName:
                foreach ($indicatorsData as $indicator) {
                    // Collect each set of values into the array
                    $values[] = "(
                        " . $indicator['company_id'] . ",
                        " . ($indicator['cf_high'] ?? 'NULL') . ",
                        " . ($indicator['cf_last'] ?? 'NULL') . ",
                        " . ($indicator['cf_low'] ?? 'NULL') . ",
                        " . ($indicator['cf_volume'] ?? 'NULL') . ",
                        " . ($indicator['high_1'] ?? 'NULL') . ",
                        " . ($indicator['hst_close'] ?? 'NULL') . ",
                        " . ($indicator['low_1'] ?? 'NULL') . ",
                        " . ($indicator['netchng_1'] ?? 'NULL') . ",
                        " . ($indicator['num_moves'] ?? 'NULL') . ",
                        " . ($indicator['open_prc'] ?? 'NULL') . ",
                        " . ($indicator['pctchng'] ?? 'NULL') . ",
                        " . ($indicator['trdprc_1'] ?? 'NULL') . ",
                        " . ($indicator['turnover'] ?? 'NULL') . ",
                        " . ($indicator['yrhigh'] ?? 'NULL') . ",
                        " . ($indicator['yrlow'] ?? 'NULL') . ",
                        " . ($indicator['yr_pctch'] ?? 'NULL') . ",
                        " . ($indicator['cf_close'] ?? 'NULL') . ",
                        " . ($indicator['bid'] ?? 'NULL') . ",
                        " . ($indicator['ask'] ?? 'NULL') . ",
                        " . ($indicator['asksize'] ?? 'NULL') . ",
                        " . ($indicator['bidsize'] ?? 'NULL') . ",
                        '" . $indicator['created_at'] . "',
                        '" . $indicator['updated_at'] . "'
                    )";
                }
                $dbQuery = "INSERT INTO " . $tableName . " (company_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close,
                low_1, netchng_1, num_moves, open_prc, pctchng, trdprc_1, turnover,
                yrhigh, yrlow, yr_pctch, cf_close, bid, ask, asksize, bidsize, created_at, updated_at)
                VALUES " . implode(", ", $values);
                break;
            default:
                break;
        }

        return $dbQuery;
    }

    public function getCompaniesFromDB()
    {
        $dbQuery = "SELECT ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        WHERE c.ric IS NOT NULL
        AND c.ric NOT LIKE '%^%'
        AND c.ric ~ '^[0-9a-zA-Z\\.]+$'";

        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $results = $channel->pop();

        // Process the results: create an associative array with 'ric' as the key and 'id' as the value
        $companyDetail = [];
        foreach ($results as $row) {
            $companyDetail[$row['ric']] = $row;
        }

        return $companyDetail;
    }

    public function getAllCompaniesWithRefDataFromDB()
    {
        $dbQuery = "SELECT r.*, ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        LEFT JOIN ref_data_snapshot_companies As r On c.id = r.company_id
        WHERE c.ric IS NOT NULL
        AND c.ric NOT LIKE '%^%'
        AND c.ric ~ '^[0-9a-zA-Z\\.]+$'";

        // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool

        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $results = $channel->pop();

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

        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $refCountInDB = $channel->pop();

        return $refCountInDB ? (($refCountInDB = $refCountInDB[0]['count']) > 0 ? $refCountInDB : false) : false;
    }

    public function processRefData($companyDetail, $tableName, $dataExistInDB = null, $dataInitCase = null)
    {
        $isProcessedRefIndicatorsData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($companyDetail);

        // Handle Refinitive responses
        if($dataExistInDB) {
            $refIndicatorsData = $this->handleRefSnapshotResponsesWithExistingData($responses, $companyDetail);

            if (count($refIndicatorsData[self::ALLCHANGEDINDICATORS]) > 0) {
                // Broadcasting changed data
                $mAIndicatorJobRunsAtData = SwooleTableFactory::getTableData(tableName: 'ma_indicator_job_runs_at');
                $mAIndicatorJobRunsAt = isset($mAIndicatorJobRunsAtData[0]['job_run_at'])
                ? $mAIndicatorJobRunsAtData[0]['job_run_at']
                : null;
                $this->broadcastIndicatorsData($refIndicatorsData[self::ALLCHANGEDINDICATORS], $mAIndicatorJobRunsAt);
            }

            $processedRecords = $dataInitCase  ?  $refIndicatorsData[self::ALLRECORDS] : $refIndicatorsData[self::ALLCHANGEDRECORDS];

            if( count($processedRecords) > 0) {
                // Load into the swoole Table
                $this->saveIntoSwooleTable($processedRecords, $tableName);
                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($processedRecords[0]['created_at']);
            }

            // Update and Insert into DB when there is changed data
            if(count($refIndicatorsData[self::ALLCHANGEDRECORDS]) > 0) {
               // Update/Save into the DB
               $this->updateRefSnapshotIntoDBTable($refIndicatorsData[self::ALLCHANGEDRECORDS], $tableName);
            }

            // Is Refinitive Data Received
            $isProcessedRefIndicatorsData = $refIndicatorsData[self::ISREFDATA];

        } else {
            $refIndicatorsData = $this->handleRefSnapshotResponses($responses, $companyDetail);

            // Initialize fresh data from Refinitive for indicators
            if (count($refIndicatorsData) > 0) {
                var_dump("Initialize fresh data from Refinitive for indicators");
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $tableName);

                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($refIndicatorsData[0]['created_at']);
                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refIndicatorsData, $tableName);

                $isProcessedRefIndicatorsData = true;
            }
        }

        return $isProcessedRefIndicatorsData;
    }

    public function saveRefJobRunAtIntoSwooleTable($jobRunAt) {
        var_dump('Save data into Swoole table ma_indicator_job_runs_at');
        $data = ['job_run_at' => $jobRunAt];
        $table = SwooleTableFactory::getTable('ma_indicator_job_runs_at');
        $table->set(0, $data);
    }
}
