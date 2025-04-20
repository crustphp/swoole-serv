<?php

namespace App\Services;

// use DB\DBConnectionPool;

// use Swoole\Timer as swTimer;
use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use App\Constants\LogMessages;
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

    protected $sPFields;
    protected $spTimeSpan;

    protected $refDayWisefields;
    protected $refDayWiseDataFetchingTimespan;

    const ERRORLOG = 'error_logs';
    const ALLCHANGEDRECORDS = 'all_changed_records';
    const REFSNAPSHOTAllDBCOMPANIES = 'companies_indicators';
    const ISREFDATA = 'is_ref_data';
    const COMPANYSWOOLETABLENAMEPRFIX = '_companies_indicators';
    const ALLCHANGEDINDICATORS = 'all_changed_indicators';
    const JOBRUNAT = 'jobs_runs_at';
    const MARKETOVERVIEW = 'markets_overview';
    const PCTCHNG = 'pctchng';
    const MARKETSUFFIX = '_overview';

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

        $this->sPFields = explode(',', config('spg_config.sp_fields'));
        $this->spTimeSpan = config('spg_config.sp_data_fetching_timespan');

        $this->refDayWisefields = config('ref_config.ref_daywise_fields');
        $this->refDayWiseDataFetchingTimespan = config('app_config.ref_daywise_fetching_timespan');
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        // Refinitiv day-wise indicators
        go(function () {
            while (true) {
                $this->initRef($this->refDayWisefields);
                Co::sleep($this->refDayWiseDataFetchingTimespan);
            }
        });

        // Refinitiv
        go(function () {
            $companyDetail = null;
            $dataExistInDB = false;
            $dataInitCase = true;

            // Get the all markets
            $markets = $this->getMarketsFromDB();

            if (!is_array($markets) || count($markets) == 0) {
                output(LogMessages::NO_MARKET_IN_DB);
            }

            // Aggregate query to get the count from the Refinitive table
            $dataExistInDB = $this->getDataCountFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

            if ($dataExistInDB) { // Case: The websocket service not running for the first time in its entirety
                // Get only companies which has Ref Data from DB.
                $companyDetailWithRefData = $this->fetchOnlyRefDataCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

                // If the data is fresh, initialize from the database
                if ($this->isFreshRefData($companyDetailWithRefData)) {
                    output(sprintf(LogMessages::RECORD_WITHIN_TIMESPAN, $this->refTimeSpan));

                    // All market companies
                    foreach ($markets as $market) {
                        if (!empty($market['refinitiv_universe'])) {
                            $marketName = strtolower($market['refinitiv_universe']);
                        } else {
                            output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                            continue;
                        }

                        $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                        if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                            output(sprintf(LogMessages::CREATE_SWOOLE_TABLE, $marketName));
                            continue;
                        }

                        go(function () use ($companyDetailWithRefData, $market, $companySwooleTableName, $marketName) {
                            // Get specific market Companies
                            $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                            // Load market's companies into swoole table
                            $this->loadSwooleTableWithRefDataFromDB($companySwooleTableName, $marketCompaniesDetailWithRefData);

                            $firstRecord = array_shift($marketCompaniesDetailWithRefData);
                            $this->saveRefJobRunAtIntoSwooleTable($companySwooleTableName, $firstRecord["updated_at"]);

                            // Get market data classification
                            $marketData = $this->getRisingFallingAndUnchangedCompanies($marketCompaniesDetailWithRefData, self::PCTCHNG);
                            // Save market status into swoole table
                            $this->saveDataIntoSwooleTable(marketData: $marketData, tableName: self::MARKETOVERVIEW, key: $marketName.self::MARKETSUFFIX);
                        });
                    }
                } else {
                    output(sprintf(LogMessages::OLDER_DATA_EXISTS, $this->refTimeSpan));
                    // Get all companies with Ref Data from the database for calculating change or delta, excluding those with a caret symbol ('^') in their RIC.
                    $companyDetailWithRefData = $this->getAllCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);

                    // All market companies
                    foreach ($markets as $market) {
                        if (!empty($market['refinitiv_universe'])) {
                            $marketName = strtolower($market['refinitiv_universe']);
                        } else {
                            output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                            continue;
                        }

                        $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                        if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                            output(sprintf(LogMessages::CREATE_SWOOLE_TABLE, $marketName));
                            continue;
                        }

                        go(function () use ($companyDetailWithRefData, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                            // Get specific market Companies
                            $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                            // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                            $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $this->fields, $dataExistInDB, $dataInitCase, $marketName);

                            if (!$isProcessedRefMarketCompaniesData) {
                                output(sprintf(LogMessages::REFINITIV_NO_DATA, 'delta', $marketName));
                            }

                            // Get only market wise companies which has Ref Data from DB.
                            $companyDetailWithRefData = $this->fetchOnlyRefDataCompaniesWithRefDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES, $market['id']);

                            // Load into swoole table
                            $this->loadSwooleTableWithRefDataFromDB($companySwooleTableName, $companyDetailWithRefData);
                            // Get market data classification
                            $marketData = $this->getRisingFallingAndUnchangedCompanies($companyDetailWithRefData, self::PCTCHNG);
                            // Save market status into swoole table
                            $this->saveDataIntoSwooleTable(marketData: $marketData, tableName: self::MARKETOVERVIEW, key: $marketName.self::MARKETSUFFIX);
                        });
                    }
                }
            } else {
                output(LogMessages::NO_REF_COMPANY_DATA);
                $companyDetail = $this->getCompaniesFromDB();
                // All market companies
                foreach ($markets as $market) {
                    if (!empty($market['refinitiv_universe'])) {
                        $marketName = strtolower($market['refinitiv_universe']);
                    } else {
                        output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                        continue;
                    }

                    $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

                    if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                        output(sprintf(LogMessages::CREATE_SWOOLE_TABLE, $marketName));
                        continue;
                    }

                    go(function () use ($companyDetail, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                        // Get specific market Companies
                        $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetail);
                        // Fetch data from Refinitive, store in swoole table, and store in DB.
                        $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $this->fields, $dataExistInDB, $dataInitCase, $marketName);

                        if (!$isProcessedRefMarketCompaniesData) {
                            output(sprintf(LogMessages::REFINITIV_NO_DATA, 'data', $marketName));
                        }
                    });
                }
            }

            while (true) {
                $this->initRef($this->fields);
                Co::sleep($this->refTimeSpan);
            }
        });

        // SP
        go(function() {
            while (true) {
                $this->initSP();
                Co::sleep($this->spTimeSpan);
            }
        });
    }

    public function initRef(string $fields)
    {
        $dataExistInDB = false;
        $dataInitCase = false;

        // Get the all markets
        $markets = $this->getMarketsFromDB();

        if (!is_array($markets) || count($markets) == 0) {
            output(LogMessages::NO_MARKET_IN_DB);
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
                output(LogMessages::ADD_MARKET_TO_REF_UNIVERSE);
                continue;
            }

            $companySwooleTableName = $marketName . self::COMPANYSWOOLETABLENAMEPRFIX;

            if (!SwooleTableFactory::getTable($companySwooleTableName)) {
                output(sprintf(LogMessages::CREATE_SWOOLE_TABLE, $marketName));
                continue;
            }

            go(function () use ($companyDetailWithRefData, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase, $fields) {
                // Get specific market Companies
                $marketCompaniesDetailWithRefData = $this->filterMarketwiseData($market['id'], $companyDetailWithRefData);
                // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                $isProcessedRefMarketCompaniesData = $this->processRefData($marketCompaniesDetailWithRefData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $fields, $dataExistInDB, $dataInitCase, $marketName);

                if (!$isProcessedRefMarketCompaniesData) {
                    output(sprintf(LogMessages::REFINITIV_NO_DATA, 'delta', $marketName));
                }
            });
        }
    }

    public function getAllCompaniesWithSPDataFromDB($tableName)
    {
        $dbQuery = "SELECT s.*, ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        LEFT JOIN $tableName As s On c.id = s.company_id
        WHERE c.sp_comp_id IS NOT NULL";

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

        // Process the results: create an associative array with 'sp_comp_id' as the key and 'id' as the value
        $companyDetail = [];
        foreach ($results as $row) {
            $companyDetail[$row['sp_comp_id']] = $row;
        }

        return $companyDetail;
    }

    public function initSP()
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
            $companyDetailWithSPData = $this->getAllCompaniesWithSPDataFromDB(self::REFSNAPSHOTAllDBCOMPANIES);
        } else {
            $dataInitCase = true;
            $companyDetailWithSPData = $this->getCompaniesForSPFromDB();
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

            go(function () use ($companyDetailWithSPData, $market, $marketName, $companySwooleTableName, $dataExistInDB, $dataInitCase) {
                // Get specific market Companies
                $marketCompaniesDetailWithSPData = $this->filterMarketwiseData($market['id'], $companyDetailWithSPData);
                // Fetch data from Refinitive, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                $isProcessedSPMarketCompaniesData = $this->processSPData($marketCompaniesDetailWithSPData, self::REFSNAPSHOTAllDBCOMPANIES, $companySwooleTableName, $dataExistInDB, $dataInitCase, $marketName);

                if (!$isProcessedSPMarketCompaniesData) {
                    var_dump("S&P API is not returning data at this time of $marketName companies");
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
        output(sprintf(LogMessages::LOADING_SWOOLE_TABLE, $tableName));
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
                'logo' => $this->getLogoUrl($indicatorDBRec['logo']),
                'market_id' =>  $indicatorDBRec['market_id'],
                'market_name' =>  $indicatorDBRec['market_name'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $data = [
                'cf_high' => $indicatorDBRec['cf_high'],
                'cf_last' => $indicatorDBRec['cf_last'],
                'cf_low' => $indicatorDBRec['cf_low'],
                'cf_volume' => $indicatorDBRec['cf_volume'],
                'high_1' => $indicatorDBRec['high_1'],
                'hst_close' => $indicatorDBRec['hst_close'],
                'low_1' => $indicatorDBRec['low_1'],
                'netchng_1' => $indicatorDBRec['netchng_1'],
                'num_moves' => $indicatorDBRec['num_moves'],
                'open_prc' => $indicatorDBRec['open_prc'],
                'pctchng' => $indicatorDBRec['pctchng'],
                'trdprc_1' => $indicatorDBRec['trdprc_1'],
                'turnover' => $indicatorDBRec['turnover'],
                'yrhigh' => $indicatorDBRec['yrhigh'],
                'yrlow' => $indicatorDBRec['yrlow'],
                'yr_pctch' => $indicatorDBRec['yr_pctch'],
                'cf_close' => $indicatorDBRec['cf_close'],
                'bid' => $indicatorDBRec['bid'],
                'ask' => $indicatorDBRec['ask'],
                'asksize' => $indicatorDBRec['asksize'],
                'bidsize' => $indicatorDBRec['bidsize'],
                'company_id' => $indicatorDBRec['company_id'],
                'sp_comp_id' =>  $indicatorDBRec['sp_comp_id'],
                'isin_code' =>  $indicatorDBRec['isin_code'],
                'ric' =>  $indicatorDBRec['ric'],
                'iq_volume' =>  $indicatorDBRec['iq_volume'],
                'iq_float' =>  $indicatorDBRec['iq_float'],
                'sp_turnover' =>  $indicatorDBRec['sp_turnover'],
                'uplimit' =>  $indicatorDBRec['uplimit'],
                'lolimit' =>  $indicatorDBRec['lolimit'],
                'life_high' =>  $indicatorDBRec['life_high'],
                'life_low' =>  $indicatorDBRec['life_low'],
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
     * @param mixed $companyDetail
     * @param string $fields
     *
     * @return mixed
     */

    public function fetchRefData(mixed $companyDetail, string $fields): mixed
    {
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('ref_config.ref_pricing_snapshot_url'), $this->refTokenLock);
        $responses = $service->handle($companyDetail, $fields);
        unset($service);

        return $responses;
    }

    public function handleRefSnapshotResponses($responses, $companyDetail, $fields)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [];
        $fields = explode(',', $fields);

        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                // This code will be saved into DB in the Refinement PR
                output(sprintf(LogMessages::REFINITIV_MISSING_INDICATORS, json_encode($res)));
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

    public function handleRefSnapshotResponsesWithExistingData($responses, $companyDetail, $marketName, $fields)
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
        $fields = explode(',', $fields);

        foreach ($responses as $res) {
            $company = isset($res['Key']["Name"]) ? $companyDetail[str_replace('/', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                // This code will be saved into DB in the Refinement PR
                output(sprintf(LogMessages::REFINITIV_MISSING_INDICATORS, json_encode($res)));
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
                    $allChangedIndicators[$marketName . $field][] = $this->appendDetails($indicator, $company, $date, false);

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

    public function handleSPSnapshotResponses($responses, $companyDetail)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $SPIndicatorData = [];
        $data = [];

        $responsesCount = count($responses);

        for($i=0; $i< $responsesCount; $i+=2) {
            $data = [];

            $company = isset($responses[$i]['Identifier']) ? $companyDetail[$responses[$i]['Identifier']] : null;

            if (!isset($responses[$i]['Rows'][0]['Row'][0]) || !isset($responses[$i+1]['Rows'][0]['Row'][0])) {
                var_dump('Missing "Fields" key in S&P api response: ' . json_encode($responses));
                continue;
            }

            $iq_volume = (float) $responses[$i]['Rows'][0]['Row'][0];
            $iq_float = (float) $responses[$i+1]['Rows'][0]['Row'][0];
            $data = [
                'iq_volume' => $iq_volume,
                'iq_float' => $iq_float,
                'sp_turnover' => ($iq_float != 0) ? formatNumber($iq_volume / $iq_float) : 0,
            ];

            if (!empty($data)) {
                $data = $this->appendDetails($data, $company, $date);
                array_push($SPIndicatorData, $data);
            }
        }

        return $SPIndicatorData;
    }

    public function handleSPResponsesWithExistingData($responses, $companyDetail, $marketName)
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');
        $marketName .='_';

        $spIndicatorData = [
            self::ALLCHANGEDINDICATORS => [],
            self::ALLCHANGEDRECORDS => [],
            self::ISREFDATA => false,
        ];

        $isChangedData = false;
        $allChangedIndicators = [];
        $indicator = [];

        $responsesCount = count($responses);

        for($i=0; $i< $responsesCount; $i+=2) {
            $company = isset($responses[$i]['Identifier']) ? $companyDetail[$responses[$i]['Identifier']] : null;

            if (!isset($responses[$i]['Rows'][0]['Row'][0]) || !isset($responses[$i+1]['Rows'][0]['Row'][0])) {
                var_dump('Missing "Fields" key in S&P api response: ' . json_encode($responses));
                continue;
            }

            $data = [];
            $isChangedData = false;

            $iq_volume = (float) $responses[$i]['Rows'][0]['Row'][0];
            $iq_float = (float) $responses[$i+1]['Rows'][0]['Row'][0];

            $sp_turnover = ($iq_float != 0) ?  formatNumber($iq_volume / $iq_float) : 0;

            // IQ_VOLUME
            if ((!isset($company['iq_volume']) || $iq_volume != $company['iq_volume'])) {
                $data['iq_volume'] = $iq_volume;

                $indicator['iq_volume'] = $iq_volume;
                // Append detail with every indicator
                $allChangedIndicators[$marketName.'iq_volume'][] = $this->appendDetails($indicator, $company, $date, false);

                $isChangedData = true;
                $spIndicatorData[self::ISREFDATA] = true;

                $indicator = []; // Reset the indicator array
            }

            // IQ_FLOAT
            if ((!isset($company['iq_float']) || $iq_float != $company['iq_float'])) {
                $data['iq_float'] = $iq_float;
                $indicator['iq_float'] = $iq_float;
                // Append detail with every indicator
                $allChangedIndicators[$marketName.'iq_float'][] = $this->appendDetails($indicator, $company, $date, false);

                $isChangedData = true;
                $spIndicatorData[self::ISREFDATA] = true;

                $indicator = []; // Reset the indicator array
            }

            // sp_turnover
            if ((!isset($company['sp_turnover']) || $sp_turnover != $company['sp_turnover'])) {
                $data['sp_turnover'] = $sp_turnover;

                $indicator['sp_turnover'] = $sp_turnover;
                // Append detail with every indicator
                $allChangedIndicators[$marketName.'sp_turnover'][] = $this->appendDetails($indicator, $company, $date, false);

                $isChangedData = true;
                $spIndicatorData[self::ISREFDATA] = true;

                $indicator = []; // Reset the indicator array
            }

            if ($isChangedData) {
                $data = $this->appendDetails($data, $company, $date);
                // All changed Records (Rows)
                $spIndicatorData[self::ALLCHANGEDRECORDS][] = $data;
            }
        }

        if(count($allChangedIndicators) > 0) {
            // All changed Indicators (Columns)
            $spIndicatorData[self::ALLCHANGEDINDICATORS] = $allChangedIndicators;
        }

        return $spIndicatorData;
    }

    public function appendDetails($data, $company, $date, $convertCompanyInfoToJson = true)
    {
        $companyInfo = [
            'ric' => $company['ric'],
            'company_id' => $company['id'],
            'en_long_name' => $company['name'],
            'sp_comp_id' => $company['sp_comp_id'],
            'en_short_name' => $company['short_name'],
            'symbol' => $company['symbol'],
            'isin_code' => $company['isin_code'],
            'ar_long_name' => $company['arabic_name'],
            'ar_short_name' => $company['arabic_short_name'],
            'logo' => $this->getLogoUrl($company['logo']),
            'market_id' => $company['market_id'],
            'market_name' => $company['market_name']
        ];

        if ($convertCompanyInfoToJson) {
            $companyInfo = json_encode($companyInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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
                    'message_data' => [
                        $key => $indicator,
                        'job_runs_at' => $mAIndicatorJobRunsAt,
                    ],
                ];

                for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
                    $this->server->sendMessage($data, $worker_id);
                }
            }
        });
    }

    public function handleChangedIndicatorAndBroadcast(array $deltaOfIndicators, string $topic, string $swooleTableName, string $indicatorColumn, string $changedIndicatorKey)
    {
        go(function () use ($deltaOfIndicators, $topic, $swooleTableName, $indicatorColumn, $changedIndicatorKey) {

            if (!isset($deltaOfIndicators[$changedIndicatorKey])) {
                return;
            }
            // Fetch data from Swoole table
            $data = SwooleTableFactory::getSwooleTableData(tableName: $swooleTableName, selectColumns: [$indicatorColumn]);

            // Get market data classification
            $marketData = $this->getRisingFallingAndUnchangedCompanies($data, $indicatorColumn);

            // Broadcast and save market data
            $this->marketDataBroadcasting($marketData, $topic);
            $this->saveDataIntoSwooleTable(marketData: $marketData, tableName: self::MARKETOVERVIEW, key: $topic);
        });
    }

    public function marketDataBroadcasting(array $marketData, string $topic)
    {
        $data = [
            'topic' => $topic,
            'message_data' => [
                $topic => $marketData
            ]
        ];

        for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
            $this->server->sendMessage($data, $worker_id);
        }
    }

    public function saveIntoSwooleTable(array $indicatorsData, string $tableName)
    {
        $table = SwooleTableFactory::getTable($tableName, true);

        $allColumns = array_filter($this->getTableColumns(self::REFSNAPSHOTAllDBCOMPANIES), function ($column) {
            return $column !== 'id'; // Exclude id column
        });

        foreach ($indicatorsData as $data) {

            $companyDataExist = $table->get($data['company_id']);
            // To Check a company data exist already
            if (empty($companyDataExist)) {
                $data = $this->addRemainingColumns($data, $allColumns);
                $table->set($data['company_id'], $data);
            } else {
                $table->set($data['company_id'], $data);
            }
        }
    }

    private function addRemainingColumns(array $data, array $columns)
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $data)) {
                $data[$column] = $this->floatEmptyValue;
            }
        }

        return $data;
    }

    public function saveRefSnapshotDataIntoDBTable(array $indicatorsData, $tableName)
    {
        output(sprintf(LogMessages::SAVE_REF_SNAPSHOT, $tableName));
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
        output(sprintf(LogMessages::UPDATE_DB_TABLE, $tableName));
        go(function () use ($indicatorsData, $tableName) {
            // Fetch all columns dynamically
            $allColumns = array_filter($this->getTableColumns($tableName), function ($column) {
                return $column !== 'id'; // Exclude primary key column
            });

            foreach ($indicatorsData as $indicator) {
                // Provided columns in request (excluding company_id)
                $providedColumns = array_keys($indicator);
                $providedColumns = array_diff($providedColumns, ['company_id']); // Exclude company_id

                // Initialize arrays for columns, values, and updates
                $columns = ['company_id'];  // Always include company_id
                $values = [$indicator['company_id']];
                $updates = [];

                // Loop through all table columns
                foreach ($allColumns as $column) {
                    // Skip unnecessary fields
                    if (in_array($column, ['company_id', 'created_at', 'updated_at', 'company_info', 'sp_comp_id', 'isin_code', 'ric'])) {
                        continue;
                    }

                    if (in_array($column, $providedColumns)) {
                        // If column is provided, use the given value
                        $value = $indicator[$column] ?? $this->floatEmptyValue;
                        $values[] = is_numeric($value) ? $value : "'$value'";
                        $updates[] = "$column = EXCLUDED.$column";
                    } else {
                        // If column is NOT provided, insert $this->floatEmptyValue but don't update it
                        $values[] = $this->floatEmptyValue;
                    }

                    // Add to the columns list
                    $columns[] = $column;
                }

                // Add created_at and updated_at columns
                $columns[] = 'created_at';
                $columns[] = 'updated_at';
                $values[] = "'" . $indicator['created_at'] . "'";
                $values[] = "'" . $indicator['updated_at'] . "'";

                // Add to update part only for provided columns
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

    private function getTableColumns(string $tableName): array
    {
        $channel = new Channel(1);

        $dbQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $tableName . "'";

        go(function () use ($dbQuery, $channel) {
            try {
                $result = $this->dbFacade->query($dbQuery, $this->objDbPool);
                $channel->push($result);
            } catch (Throwable $e) {
                output($e);
            }
        });

        $results = $channel->pop();
        return array_column($results, 'column_name');
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
                        " . ($indicator['iq_volume'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['iq_float'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['sp_turnover'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['uplimit'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['lolimit'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['life_high'] ?? $this->floatEmptyValue) . ",
                        " . ($indicator['life_low'] ?? $this->floatEmptyValue) . ",
                        '" . $indicator['created_at'] . "',
                        '" . $indicator['updated_at'] . "'
                    )";
                }
                $dbQuery = "INSERT INTO " . $tableName . " (company_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close,
                low_1, netchng_1, num_moves, open_prc, pctchng, trdprc_1, turnover,
                yrhigh, yrlow, yr_pctch, cf_close, bid, ask, asksize, bidsize, iq_volume, iq_float, sp_turnover, uplimit, lolimit, life_high, life_low, created_at, updated_at)
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

    public function getCompaniesForSPFromDB()
    {
        $dbQuery = "SELECT ric, c.id, c.name, sp_comp_id, short_name, symbol, isin_code, arabic_name, arabic_short_name, logo, parent_id as market_id, m.name as market_name FROM companies as c
        INNER JOIN markets As m On c.parent_id = m.id
        WHERE c.sp_comp_id IS NOT NULL";

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

        // Process the results: create an associative array with 'sp_comp_id' as the key and 'id' as the value
        $companyDetail = [];
        foreach ($results as $row) {
            $companyDetail[$row['sp_comp_id']] = $row;
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

    public function processRefData($companyDetail, $dbTableName, $swooleTableName, $fields, $dataExistInDB = null, $dataInitCase = null, $marketName = null)
    {
        $isProcessedRefIndicatorsData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($companyDetail, $fields);

        // Handle Refinitive responses
        if ($dataExistInDB) {
            $refIndicatorsData = $this->handleRefSnapshotResponsesWithExistingData($responses, $companyDetail, $marketName, $fields);
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
                // Check if the PCTCHNG indicator has changed, then save and broadcast the data
                $this->handleChangedIndicatorAndBroadcast($refIndicatorsData[self::ALLCHANGEDINDICATORS], $marketName.self::MARKETSUFFIX, $swooleTableName, self::PCTCHNG, $marketName.'_'.self::PCTCHNG);
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
            $refIndicatorsData = $this->handleRefSnapshotResponses($responses, $companyDetail, $fields);

            // Initialize fresh data from Refinitiv for indicators
            if (count($refIndicatorsData) > 0) {
                output(LogMessages::INITIALIZE_REFINDICATORS);
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $swooleTableName);
                // Load Job run at into swoole table
                $this->saveRefJobRunAtIntoSwooleTable($swooleTableName, $refIndicatorsData[0]['updated_at']);

                // Check if the PCTCHNG indicator exists before performing market classification
                if(array_key_exists(self::PCTCHNG, $refIndicatorsData[0])) {
                    // Get market data classification
                    $marketData = $this->getRisingFallingAndUnchangedCompanies($refIndicatorsData, self::PCTCHNG);
                    // Save market status into swoole table
                    $this->saveDataIntoSwooleTable(marketData: $marketData, tableName: self::MARKETOVERVIEW, key: $marketName.self::MARKETSUFFIX);
                }
                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refIndicatorsData, $dbTableName);

                $isProcessedRefIndicatorsData = true;
            }
        }

        return $isProcessedRefIndicatorsData;
    }

    public function processSPData($companyDetail, $dbTableName, $swooleTableName, $dataExistInDB = null, $dataInitCase = null, $marketName = null)
    {
        $isProcessedSPIndicatorsData = false;
        // Fetch data from SP
        $responses = $this->fetchSPData($companyDetail);

        // Handle SP responses
        if ($dataExistInDB) {
            $refIndicatorsData = $this->handleSPResponsesWithExistingData($responses, $companyDetail, $marketName);

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

            // Update and Insert into DB when there is changed data
            if (count($processedRecords) > 0) {
                // Update/Save into the DB
                $this->updateRefSnapshotIntoDBTable($processedRecords, $dbTableName);
            }

            // Is SP Data Received
            $isProcessedSPIndicatorsData = $refIndicatorsData[self::ISREFDATA];
        } else {
            $refIndicatorsData = $this->handleSPSnapshotResponses($responses, $companyDetail);

            // Initialize fresh data from SP for indicators
            if (count($refIndicatorsData) > 0) {
                var_dump("Initialize fresh data from SP for indicators");
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $swooleTableName);

                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refIndicatorsData, $dbTableName);

                $isProcessedSPIndicatorsData = true;
            }
        }

        return $isProcessedSPIndicatorsData;
    }

    /**
     * SP data fetching
     *
     * @param  mixed  $companyDetail
     * @return void
     */

    public function fetchSPData(mixed $companyDetail)
    {
        $service = new SPAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('spg_config.sp_global_api_uri') . '/clientservice.json');
        $responses = $service->handle($companyDetail, $this->sPFields);
        unset($service);

        return $responses;
    }

    public function saveRefJobRunAtIntoSwooleTable($jobName, $jobRunAt)
    {
        output(sprintf(LogMessages::REF_SAVE_SWOOLE_TABLE, self::JOBRUNAT));
        $table = SwooleTableFactory::getTable(self::JOBRUNAT);
        $table->set($jobName, ['job_name' => $jobName,'job_run_at' => $jobRunAt]);
    }

    public function getRisingFallingAndUnchangedCompanies(array $data, string $indicator)
    {
        $result = [
            "rising_companies" => 0,
            "falling_companies" => 0,
            "unchanged_companies" => 0,
        ];

        foreach ($data as $company) {
            if ($company[$indicator] > 0) {
                $result["rising_companies"]++;
            } elseif ($company[$indicator] < 0 && $company[$indicator]!= $this->floatEmptyValue) {
                $result["falling_companies"]++;
            } elseif ($company[$indicator] == 0) {
                $result["unchanged_companies"]++;
            }
        }

        return $result;
    }

    public function saveDataIntoSwooleTable(array $marketData, string $tableName, string $key)
    {
        output(sprintf(LogMessages::REF_SAVE_SWOOLE_TABLE, $tableName));
        $table = SwooleTableFactory::getTable($tableName);
        $table->set($key, $marketData);
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

    public function getLogoUrl($logo) {
        return $logo ? config('app_config.laravel_app_url').'storage/'.$logo : $logo;
    }

}
