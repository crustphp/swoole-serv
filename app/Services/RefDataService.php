<?php

namespace App\Services;

// use DB\DBConnectionPool;

// use Swoole\Timer as swTimer;
use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Throwable;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine as Co;


class RefDataService
{
    protected $server;
    protected $process;
    protected $worker_id;
    protected $objDbPool;
    protected $dbFacade;
    protected $fields;
    protected $refTimeSpan;

    protected $refTokenLock;
    protected $floatEmptyValue;

    const ERRORLOG = 'error_logs';
    const ALLCHANGEDRECORDS = 'all_changed_records';
    const REFSNAPSHOTAllDBCOMPANIES = 'companies_indicators';
    const ISREFDATA = 'is_ref_data';
    const COMPANYSWOOLETABLENAMEPRFIX = '_companies_indicators';
    const ALLCHANGEDINDICATORS = 'all_changed_indicators';
    const JOBRUNAT = 'jobs_runs_at';

    public function __construct($server, $process, $objDbPool, $refTokenLock = null)
    {
        $GLOBALS['process_id'] = $process->id;
        $this->server = $server;
        $this->process = $process;
        $this->worker_id = $process->id;

        $this->objDbPool = $objDbPool;
        $this->dbFacade = new DbFacade();

        $this->fields = config('ref_config.ref_fields');
        $this->refTimeSpan = config('app_config.most_active_data_fetching_timespan');
        $this->refTokenLock = $refTokenLock;
        $this->floatEmptyValue = config('app_config.float_empty_value');
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        go(function () {
            $companyDetail = null;
            $dataExistInDB = false;
            $dataInitCase = true;

            // Get the all markets
            $markets = $this->getMarketsFromDB();

            if (!is_array($markets) || count($markets) == 0) {
                var_dump("There is no market that exists in the database.");
            }

            // Aggregate query to get the count from the Refinitive table
            $dataExistInDB = $this->getDataCountFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

            if ($dataExistInDB) { // Case: The websocket service not running for the first time in its entirety
                // Get only companies which has Ref Data from DB.
                $companyDetailWithRefData = $this->fetchOnlyRefDataCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

                // If the data is fresh, initialize from the database
                if ($this->isFreshRefData($companyDetailWithRefData)) {
                    var_dump("Record is within the last $this->refTimeSpan seconds. Data prepared.");

                    // All market companies
                    foreach ($markets as $market) {
                        if (!empty($market['refinitiv_universe'])) {
                            $marketName = strtolower($market['refinitiv_universe']);
                        } else {
                            var_dump("Please add the name of the market to the refinitiv_universe column in the markets DB table.");
                            continue;
                        }

                        $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                        if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                            var_dump("Please create swoole table to save data of $marketName companies");
                            continue;
                        }

                        go(function () use ($companyDetailWithRefData, $market, $companySwooleTableName) {
                            // Get specific market Companies
                            $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                            // Load market's companies into swoole table
                            $this->loadSwooleTableWithRefDataFromDB($companySwooleTableName, $marketCompaniesDetailWithRefData);

                            $firstRecord = array_shift($marketCompaniesDetailWithRefData);
                            $this->saveRefJobRunAtIntoSwooleTable($companySwooleTableName, $firstRecord["updated_at"]);
                        });
                    }
                } else {
                    var_dump("There is older data than $this->refTimeSpan seconds");
                    // Get all companies with Ref Data from the database for calculating change or delta, excluding those with a caret symbol ('^') in their RIC.
                    $companyDetailWithRefData = $this->getAllCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

                    // All market companies
                    foreach ($markets as $market) {
                        if (!empty($market['refinitiv_universe'])) {
                            $marketName = strtolower($market['refinitiv_universe']);
                        } else {
                            var_dump("Please add the name of the market to the refinitiv_universe column in the markets DB table.");
                            continue;
                        }

                        $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                        if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                            var_dump("Please create swoole table to save data of $marketName companies");
                            continue;
                        }

                        go(function () use ($companyDetailWithRefData, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                            // Get specific market Companies
                            $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                            // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                            $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $dataExistInDB, $dataInitCase, $marketName);

                            if (!$isProcessedRefMarketCompaniesData) {
                                var_dump("Refinitive API is not returning data at this time of $marketName companies");
                            }

                            // Get only market wise companies which has Ref Data from DB.
                            $companyDetailWithRefData = $this->fetchOnlyRefDataCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES, $market['id']);

                            // Load into swoole table
                            $this->loadSwooleTableWithRefDataFromDB($companySwooleTableName, $companyDetailWithRefData);
                        });
                    }
                }
            } else {
                var_dump("There is no Refinitive Company data in DB");
                $companyDetail = $this->getCompaniesFromDB();
                // All market companies
                foreach ($markets as $market) {
                    if (!empty($market['refinitiv_universe'])) {
                        $marketName = strtolower($market['refinitiv_universe']);
                    } else {
                        var_dump("Please add the name of the market to the refinitiv_universe column in the markets DB table.");
                        continue;
                    }

                    $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                    if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                        var_dump("Please create swoole table to save data of $marketName companies");
                        continue;
                    }

                    go(function () use ($companyDetail, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                        // Get specific market Companies
                        $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetail);
                        // Fetch data from Refinitive, store in swoole table, and store in DB.
                        $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $dataExistInDB, $dataInitCase);

                        if (!$isProcessedRefMarketCompaniesData) {
                            var_dump("Refinitive API is not returning data at this time of $marketName companies");
                        }
                    });
                }
            }

            while (true) {
                $this->initRef();
                Co::sleep($this->refTimeSpan);
            }
        });
    }

    public function initRef()
    {
        $dataExistInDB = false;
        $dataInitCase = false;

        // Get the all markets
        $markets = $this->getMarketsFromDB();

        if (!is_array($markets) || count($markets) == 0) {
            var_dump("There is no market that exists in the database.");
        }

        // Aggregate query to get the count from the Refinitive table
        $dataExistInDB = $this->getDataCountFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

        if ($dataExistInDB) {
            $dataExistInDB = true;
            $companyDetailWithRefData = $this->getAllCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);
        } else {
            $dataInitCase = true;
            $companyDetailWithRefData = $this->getCompaniesFromDB();
        }

        // All market companies
        foreach ($markets as $market) {
            if (!empty($market['refinitiv_universe'])) {
                $marketName = strtolower($market['refinitiv_universe']);
            } else {
                var_dump("Please add the name of the market to the refinitiv_universe column in the markets DB table.");
                continue;
            }

            $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

            if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                var_dump("Please create swoole table to save data of $marketName companies");
                continue;
            }

            go(function () use ($companyDetailWithRefData, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                // Get specific market Companies
                $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $dataExistInDB, $dataInitCase, $marketName);

                if (!$isProcessedRefMarketCompaniesData) {
                    var_dump("Refinitive API is not returning data at this time of $marketName companies");
                }
            });
        }
    }

    public function isFreshRefData($indicatorDataFromDB)
    {
        $isFreshDBData = false;
        // Parse the latest_update timestamp
        $latestUpdate = Carbon::parse($indicatorDataFromDB[0]['updated_at']);
        // Check if the latest update is more than 5 minutes old
        if ($latestUpdate->diffInSeconds(Carbon::now()) < $this->refTimeSpan) {
            $isFreshDBData = true;
        }

        return $isFreshDBData;
    }

    public function fetchOnlyRefDataCompaniesWithRefDataFromDB($tableName, $marketId = null)
    {
        $dbQuery = 'SELECT refTable.*, c.name AS en_long_name, c.sp_comp_id, c.short_name AS en_short_name, c.symbol,
        c.isin_code, c.arabic_name AS ar_long_name, c.arabic_short_name AS ar_short_name, c.ric
        ,logo, parent_id as market_id, m.name as market_name
        FROM ' . $tableName . ' refTable JOIN companies c ON refTable.company_id = c.id
        INNER JOIN markets As m On c.parent_id = m.id';

        // Append the WHERE clause if $marketId is provided
        if ($marketId) {
            $dbQuery .= ' WHERE c.parent_id = ' . intval($marketId);
        }

        $dbQuery .= ' ORDER BY refTable.updated_at DESC';

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

    public function loadSwooleTableWithRefDataFromDB($tableName, $indicatorsDBData)
    {
        var_dump("Loading data into the $tableName swoole table.");
        $companyInfo = "";
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($indicatorsDBData as $indicatorDBRec) {
            $companyInfo = json_encode([
                'company_id' => $indicatorDBRec['company_id'],
                'en_long_name' =>  $indicatorDBRec['en_long_name'],
                'sp_comp_id' =>  $indicatorDBRec['sp_comp_id'],
                'en_short_name' =>  $indicatorDBRec['en_short_name'],
                'symbol' =>  $indicatorDBRec['symbol'],
                'isin_code' =>  $indicatorDBRec['isin_code'],
                'ar_long_name' =>  $indicatorDBRec['ar_long_name'],
                'ar_short_name' =>  $indicatorDBRec['ar_short_name'],
                'ric' =>  $indicatorDBRec['ric'],
                'logo' =>  $indicatorDBRec['logo'],
                'market_id' =>  $indicatorDBRec['market_id'],
                'market_name' =>  $indicatorDBRec['market_name'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $data = [
                'cf_high' => (float)$indicatorDBRec['cf_high'],
                'cf_last' => (float)$indicatorDBRec['cf_last'],
                'cf_low' => (float)$indicatorDBRec['cf_low'],
                'cf_volume' => (float)$indicatorDBRec['cf_volume'],
                'high_1' => (float)$indicatorDBRec['high_1'],
                'hst_close' => (float)$indicatorDBRec['hst_close'],
                'low_1' => (float)$indicatorDBRec['low_1'],
                'netchng_1' => (float)$indicatorDBRec['netchng_1'],
                'num_moves' => (float)$indicatorDBRec['num_moves'],
                'open_prc' => (float)$indicatorDBRec['open_prc'],
                'pctchng' => (float)$indicatorDBRec['pctchng'],
                'trdprc_1' => (float)$indicatorDBRec['trdprc_1'],
                'turnover' => (float)$indicatorDBRec['turnover'],
                'yrhigh' => (float)$indicatorDBRec['yrhigh'],
                'yrlow' => (float)$indicatorDBRec['yrlow'],
                'yr_pctch' => (float)$indicatorDBRec['yr_pctch'],
                'cf_close' => (float)$indicatorDBRec['cf_close'],
                'bid' => (float)$indicatorDBRec['bid'],
                'ask' => (float)$indicatorDBRec['ask'],
                'asksize' => (float)$indicatorDBRec['asksize'],
                'bidsize' => (float)$indicatorDBRec['bidsize'],
                'company_id' => $indicatorDBRec['company_id'],
                'sp_comp_id' =>  $indicatorDBRec['sp_comp_id'],
                'isin_code' =>  $indicatorDBRec['isin_code'],
                'ric' =>  $indicatorDBRec['ric'],
                'created_at' =>  $indicatorDBRec['created_at'],
                'updated_at' =>  $indicatorDBRec['updated_at'],
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
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('ref_config.ref_pricing_snapshot_url'), $this->refTokenLock);
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
                $value = $res['Fields'][$field] ?? $this->floatEmptyValue;
                $field = strtolower($field);

                $d[$field] = $value;
            }

            if (!empty($d)) {
                $d = $this->appendDetails($d, $company, $date);
                array_push($refSnapshotIndicatorData, $d);
            }
        }

        return $refSnapshotIndicatorData;
    }

    public function handleRefSnapshotResponsesWithExistingData($responses, $companyDetail, $marketName)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');
        $marketName .='_';

        $refSnapshotIndicatorData = [
            self::ALLCHANGEDINDICATORS => [],
            self::ALLCHANGEDRECORDS => [],
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
                $value = $res['Fields'][$field] ?? $this->floatEmptyValue;
                $field = strtolower($field);

                if ($value == $this->floatEmptyValue && !isset($company[$field])) {
                    $d[$field] = $value;
                } else if (
                    $value != $this->floatEmptyValue &&
                    (!isset($company[$field]) ||
                        $value != $company[$field])
                ) {

                    $d[$field] = $value;
                    $indicator[$field] = $value;

                    // Append detail with every indicator
                    $allChangedIndicators[$marketName . $field][] = $this->appendDetails($indicator, $company, $date);

                    $isChangedData = true;
                    $refSnapshotIndicatorData[self::ISREFDATA] = true;

                    $indicator = []; // Reset the indicator array
                }
            }

            if ($isChangedData) {
                $d = $this->appendDetails($d, $company, $date);
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
                // Initialize arrays for columns, values, and updates
                $columns = ['company_id'];
                $values = [$indicator['company_id']];
                $updates = [];

                // Loop through each indicator field
                foreach ($indicator as $key => $value) {
                    // Skip company_id, created_at, and updated_at as they're handled separately
                    if (in_array($key, ['company_id', 'created_at', 'updated_at', 'company_info', 'sp_comp_id', 'isin_code', 'ric'])) {
                        continue;
                    }

                    // Add column and value
                    $columns[] = $key;
                    $values[] = $value ?? $this->floatEmptyValue;

                    // Prepare update part
                    $updates[] = "$key = EXCLUDED.$key";
                }

                // Add created_at and updated_at columns
                $columns[] = 'created_at';
                $columns[] = 'updated_at';
                $values[] = "'" . $indicator['created_at'] . "'";
                $values[] = "'" . $indicator['updated_at'] . "'";

                // Add to update part as well
                $updates[] = "created_at = EXCLUDED.created_at";
                $updates[] = "updated_at = EXCLUDED.updated_at";

                // Build the query
                $dbQuery = "INSERT INTO $tableName (" . implode(', ', $columns) . ")
                            VALUES (" . implode(', ', $values) . ")
                            ON CONFLICT (company_id) DO UPDATE SET " . implode(', ', $updates) . ";";

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
            case self::REFSNAPSHOTAllDBCOMPANIES == $tableName:
                foreach ($indicatorsData as $indicator) {
                    // Collect each set of values into the array
                    $values[] = "(
                        " . $indicator['company_id'] . ",
                        " . ($indicator['cf_high'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['cf_last'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['cf_low'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['cf_volume'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['high_1'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['hst_close'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['low_1'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['netchng_1'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['num_moves'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['open_prc'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['pctchng'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['trdprc_1'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['turnover'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['yrhigh'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['yrlow'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['yr_pctch'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['cf_close'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['bid'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['ask'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['asksize'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['bidsize'] ?? $this->floatEmptyValue) . ",
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

    public function getAllCompaniesWithRefDataFromDB($tableName)
    {
        $dbQuery = "SELECT r.*, ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        LEFT JOIN $tableName As r On c.id = r.company_id
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

    public function processRefData($companyDetail, $dbTableName, $swooleTableName, $dataExistInDB = null, $dataInitCase = null, $marketName = null)
    {
        $isProcessedRefIndicatorsData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($companyDetail);

        // Handle Refinitive responses
        if ($dataExistInDB) {
            $refIndicatorsData = $this->handleRefSnapshotResponsesWithExistingData($responses, $companyDetail, $marketName);
            if (count($refIndicatorsData[self::ALLCHANGEDINDICATORS]) > 0) {
                // Get the job_run_at value from the Swoole Table
                $jobRunsAtData = getJobRunAt($swooleTableName);
                // Broadcasting changed data
                $this->broadcastIndicatorsData($refIndicatorsData[self::ALLCHANGEDINDICATORS], $jobRunsAtData);
            }

            $processedRecords = $refIndicatorsData[self::ALLCHANGEDRECORDS];

            if (!$dataInitCase && count($processedRecords) > 0) {
                // Load into the swoole Table
                $this->saveIntoSwooleTable($processedRecords, $swooleTableName);
            }

            // Load Job run at into swoole table
            $this->saveRefJobRunAtIntoSwooleTable($swooleTableName, isset($processedRecords[0]['updated_at']) ? $processedRecords[0]['updated_at'] : Carbon::now()->format('Y-m-d H:i:s'));

            // Update and Insert into DB when there is changed data
            if (count($processedRecords) > 0) {
                // Update/Save into the DB
                $this->updateRefSnapshotIntoDBTable($processedRecords, $dbTableName);
            }

            // Is Refinitive Data Received
            $isProcessedRefIndicatorsData = $refIndicatorsData[self::ISREFDATA];
        } else {
            $refIndicatorsData = $this->handleRefSnapshotResponses($responses, $companyDetail);

            // Initialize fresh data from Refinitive for indicators
            if (count($refIndicatorsData) > 0) {
                var_dump("Initialize fresh data from Refinitive for indicators");
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $swooleTableName);

                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($swooleTableName, $refIndicatorsData[0]['updated_at']);
                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refIndicatorsData, $dbTableName);

                $isProcessedRefIndicatorsData = true;
            }
        }

        return $isProcessedRefIndicatorsData;
    }

    public function saveRefJobRunAtIntoSwooleTable($jobName, $jobRunAt)
    {
        output('Save data into Swoole table '.self::JOBRUNAT);
        $table = SwooleTableFactory::getTable(self::JOBRUNAT);
        $table->set($jobName, ['job_name' => $jobName,'job_run_at' => $jobRunAt]);
    }

    public function filterMarketwiseData($marketId, $companyDetailWithRefData,)
    {
        return array_filter($companyDetailWithRefData, function ($market) use ($marketId) {
            return $market['market_id'] === $marketId;
        });
    }

    public function getMarketsFromDB()
    {
        $dbQuery = "SELECT id, refinitiv_universe from markets";

        $channel = new Channel(1);
        go(function () use ( $dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        return $channel->pop();
    }

}
