<?php

namespace App\Services;

use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use App\Constants\LogMessages;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Swoole\Coroutine as Co;


class RefMarketDataService
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

    const ALL_CHANGED_RECORDS = 'all_changed_records';
    const REF_SNAPSHOT_MARKET_TABLE_NAME = 'markets_indicators';
    const IS_REF_DATA = 'is_ref_data';
    const ALL_CHANGED_INDICATORS = 'all_changed_indicators';
    const JOB_RUN_AT = 'jobs_runs_at';
    const REFINITIV_UNIVERSE = 'refinitiv_universe';

    public function __construct($server, $process, $objDbPool, $refTokenLock = null)
    {
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
    public function handle(): void
    {
        go(function () {
            $marketsDetail = null;
            $dataExistInDB = false;
            $dataInitCase = true;

            // Aggregate query to get the count from the Database table
            $dataExistInDB = $this->getDataCountFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);

            if ($dataExistInDB) { // Case: The websocket service not running for the first time in its entirety
                // Get only markets which has Ref Data from DB.
                $marketDetailWithRefData = $this->fetchOnlyRefDataMarketsWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);

                // If the data is fresh, initialize from the database
                if ($this->isFreshRefData($marketDetailWithRefData)) {
                    output(sprintf(LogMessages::REF_MARKET_RECORD_WITHIN_TIMESPAN, $this->refTimeSpan));

                    go(function () use ($marketDetailWithRefData) {
                        // Load markets data into swoole table
                        $this->loadSwooleTableWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME, $marketDetailWithRefData, self::REFINITIV_UNIVERSE);

                        // Load job run at into swoole table
                        $this->saveIntoSwooleTable([['job_name' => self::REF_SNAPSHOT_MARKET_TABLE_NAME, 'job_run_at' => array_shift($marketDetailWithRefData)['updated_at']]], self::JOB_RUN_AT, 'job_name');
                    });
                } else {
                    output(sprintf(LogMessages::REF_MARKET_OLDER_DATA_EXISTS, $this->refTimeSpan));

                    go(function () use ($dataExistInDB, $dataInitCase) {
                        // Get all markets with Ref Data from the database for calculating change or delta.
                        $marketDetailWithRefData = $this->getAllMarketsWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);
                        // Fetch data from Refinitiv, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                        $isProcessedRefMarketData = $this->processRefData($marketDetailWithRefData, self::REF_SNAPSHOT_MARKET_TABLE_NAME, $dataExistInDB, $dataInitCase, self::REFINITIV_UNIVERSE);

                        if (!$isProcessedRefMarketData) {
                            output(sprintf(LogMessages::REFINITIV_NO_MARKET_DATA, 'delta'));
                        }

                        // Get the markets which have Ref Data from DB.
                        $marketDetailWithRefData = $this->fetchOnlyRefDataMarketsWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);
                        // Load into swoole table
                        $this->loadSwooleTableWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME, $marketDetailWithRefData, self::REFINITIV_UNIVERSE);
                    });
                }
            } else {
                output(LogMessages::NO_REF_MARKET_DATA);
                $marketsDetail = $this->getMarketsFromDB();

                go(function () use ($marketsDetail, $dataExistInDB, $dataInitCase) {
                    // Fetch data from Refinitiv, store in swoole table, and store in DB.
                    $isProcessedRefMarketData = $this->processRefData($marketsDetail, self::REF_SNAPSHOT_MARKET_TABLE_NAME, $dataExistInDB, $dataInitCase, self::REFINITIV_UNIVERSE);

                    if (!$isProcessedRefMarketData) {
                        output(sprintf(LogMessages::REFINITIV_NO_MARKET_DATA, 'data'));
                    }
                });
            }

            while (true) {
                $this->initRef();
                Co::sleep($this->refTimeSpan);
            }
        });
    }

    /**
     * Initialize Reference Market Data
     *
     * Checks for existing market data in the database, retrieves or initializes it,
     * processes changes, broadcasts updates, and stores the results.
     *
     * @return void
     */
    public function initRef(): void
    {
        $dataExistInDB = false;
        $dataInitCase = false;

        go(function () use ($dataExistInDB, $dataInitCase) {
            // Aggregate query to get the count from the Database table
            $dataExistInDB = $this->getDataCountFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);

            if ($dataExistInDB) {
                $dataExistInDB = true;
                $marketsDetailWithRefData = $this->getAllMarketsWithRefDataFromDB(self::REF_SNAPSHOT_MARKET_TABLE_NAME);
            } else {
                $dataInitCase = true;
                $marketsDetailWithRefData = $this->getMarketsFromDB();
            }

            // Fetch data from Refinitiv, calculate changes, broadcasting, update in swoole table, and store/update in DB.
            $isProcessedRefMarketsData = $this->processRefData($marketsDetailWithRefData, self::REF_SNAPSHOT_MARKET_TABLE_NAME, $dataExistInDB, $dataInitCase, self::REFINITIV_UNIVERSE);

            if (!$isProcessedRefMarketsData) {
                output(sprintf(LogMessages::REFINITIV_NO_MARKET_DATA, $dataExistInDB ? 'delta' : 'data'));
            }
        });
    }

    /**
     * Check if Data Exists in the Database
     *
     * Executes a database query to count records in the specified table.
     *
     * @param string $tableName The name of the database table to check.
     * @return bool True if records exist, false otherwise.
     */
    public function getDataCountFromDB(string $tableName): bool
    {
        $dbQuery = "SELECT count(*)  FROM " . $tableName;

        $refCountInDB = executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);

        return $refCountInDB ? (($refCountInDB = $refCountInDB[0]['count']) > 0 ? $refCountInDB : false) : false;
    }

    /**
     * Retrieve Markets Data from Database
     *
     * Fetches market records where 'refinitiv_universe' is valid and not null,
     * then maps 'refinitiv_universe' as the key with corresponding row data.
     *
     * @return array Associative array with 'refinitiv_universe' as keys.
     */
    public function getMarketsFromDB(): mixed
    {
        $dbQuery = "SELECT * FROM markets WHERE refinitiv_universe IS NOT NULL AND refinitiv_universe NOT LIKE '%^%' AND refinitiv_universe ~ '^[0-9a-zA-Z\\.]+$'";

        $results = executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
        // Process the results: create an associative array with 'refinitiv_universe' as the key and 'id' as the value
        $marketDetail = [];
        foreach ($results as $row) {
            $marketDetail[$row[self::REFINITIV_UNIVERSE]] = $row;
        }

        return $marketDetail;
    }

    /**
     * Process Reference Data
     *
     * Fetches, processes, and stores market data.
     * Updates existing records or initializes new data based on DB state.
     *
     * @param mixed      $marketDetail     Market details to process.
     * @param string     $dbTableName      Database table name.
     * @param bool|null  $dataExistInDB    Whether data exists in the DB.
     * @param bool|null  $dataInitCase     Whether it's a fresh initialization.
     * @param string     $columnName       Column for identification.
     * @param string     $marketName       Market type/name (default: 'market').
     * @param string     $marketTableName  Market table name (default: 'markets').
     * @return bool                         True if successful, false otherwise.
     */
    public function processRefData(mixed $marketDetail, string $dbTableName, ?bool $dataExistInDB = null, ?bool $dataInitCase = null, string $columnName, string $marketName = 'market', $marketTableName = 'markets'): bool
    {
        $isProcessedRefIndicatorsData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($marketDetail, $columnName, $marketTableName);

        // Handle Refinitive responses
        if ($dataExistInDB) {
            $refIndicatorsData = $this->handleRefSnapshotResponsesWithExistingData($responses, $marketDetail, $marketName);
            if (count($refIndicatorsData[self::ALL_CHANGED_INDICATORS]) > 0) {
                // Get the job_run_at value from the Swoole Table
                $jobRunsAtData = getJobRunAt($dbTableName);
                // Broadcasting changed data
                $this->broadcastIndicatorsData($refIndicatorsData[self::ALL_CHANGED_INDICATORS], $jobRunsAtData);
            }

            $processedRecords = $refIndicatorsData[self::ALL_CHANGED_RECORDS];

            if (!$dataInitCase && count($processedRecords) > 0) {
                // Load into the swoole Table
                $this->saveIntoSwooleTable($processedRecords, $dbTableName, $columnName);
            }

            // Save job run at into swoole table
            $this->saveIntoSwooleTable([['job_name' => $dbTableName, 'job_run_at' => isset($processedRecords[0]['updated_at']) ? $processedRecords[0]['updated_at'] : Carbon::now()->format('Y-m-d H:i:s')]], self::JOB_RUN_AT, 'job_name');
            // Update and Insert into DB when there is changed data
            if (count($processedRecords) > 0) {
                // Update/Save into the DB
                $this->updateRefSnapshotIntoDBTable($processedRecords, $dbTableName, 'market_id');
            }

            // Is Refinitive Data Received
            $isProcessedRefIndicatorsData = $refIndicatorsData[self::IS_REF_DATA];
        } else {
            $refIndicatorsData = $this->handleRefSnapshotResponses($responses, $marketDetail, $columnName);

            // Initialize fresh data from Refinitiv for indicators
            if (count($refIndicatorsData) > 0) {
                output(LogMessages::INITIALIZE_MARKET_REFINDICATORS);
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $dbTableName, $columnName);
                // Save job run at into swoole table
                $this->saveIntoSwooleTable([['job_name' => $dbTableName, 'job_run_at' => $refIndicatorsData[0]['updated_at']]], self::JOB_RUN_AT, 'job_name');
                // Save into DB Table
                $this->saveRefSnapshotDataIntoDBTable($refIndicatorsData, $dbTableName);

                $isProcessedRefIndicatorsData = true;
            }
        }

        return $isProcessedRefIndicatorsData;
    }

    /**
     * Save Data into Swoole Table
     *
     * Stores indicator data into the specified Swoole table using a unique key.
     *
     * @param  array  $indicatorsData  Data to be stored in the Swoole table.
     * @param  string $tableName       Name of the Swoole table.
     * @param  string $key             Unique key used to store each data entry.
     * @return void
     */
    public function saveIntoSwooleTable(array $indicatorsData, string $tableName, string $key): void
    {
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($indicatorsData as $data) {
            $table->set($data[$key], $data);
        }
    }

    /**
     * Update or Insert Data into Swoole Table
     *
     * Updates existing data or inserts new data into the specified Swoole table.
     * If a record with the given key exists, it is updated; otherwise, a new record is inserted.
     *
     * @param  array  $indicatorsData  Data to be inserted or updated in the Swoole table.
     * @param  string $tableName       Name of the Swoole table.
     * @param  string $key             Unique key used to identify each data entry.
     * @return void
     */
    public function updateIntoSwoole(array $indicatorsData, string $tableName, string $key): void
    {
        $table = SwooleTableFactory::getTable($tableName, true);

        $allColumns = array_filter($this->getTableColumns($tableName), function ($column) {
            return $column !== 'id'; // Exclude id column
        });

        foreach ($indicatorsData as $data) {

            $marketDataExist = $table->get($data[$key]);
            // To Check a market data exist already
            if (empty($marketDataExist)) {
                $data = $this->addRemainingColumns($data, $allColumns);
                $table->set($data[$key], $data);
            } else {
                $table->set($data[$key], $data);
            }
        }
    }

    /**
     * Get Table Column Names
     *
     * Retrieves the list of column names from the specified database table.
     *
     * @param  string $tableName  The name of the database table.
     * @return array              An array of column names.
     */
    private function getTableColumns(string $tableName): array
    {
        $dbQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $tableName . "'";

        $results = executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
        return array_column($results, 'column_name');
    }

    /**
     * Add Missing Columns with Default Value
     *
     * Ensures all specified columns exist in the data array.
     * Missing columns are added with a default float value.
     *
     * @param  array $data     The original data array.
     * @param  array $columns  The list of expected column names.
     * @return array           The updated data array with all columns.
     */
    private function addRemainingColumns(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $data)) {
                $data[$column] = $this->floatEmptyValue;
            }
        }

        return $data;
    }

    /**
     * Fetch Market Data from Refinitiv
     *
     * Retrieves market data from the Refinitiv API for specified markets.
     *
     * @param  mixed  $marketDetail  Market details to fetch data for.
     * @param  string $columnName    Column name for identification.
     * @param  string $tableName     Database table name.
     * @return mixed                 API response data.
     */
    public function fetchRefData(mixed $marketDetail, string $columnName, string $tableName): mixed
    {
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('ref_config.ref_pricing_snapshot_url'), $this->refTokenLock);
        $responses = $service->handle($marketDetail, $this->fields, $columnName, $tableName);
        unset($service);

        return $responses;
    }

    /**
     * Process Refinitiv Snapshot Responses
     *
     * Handles and processes snapshot responses from Refinitiv, extracting relevant market indicator data.
     *
     * @param  mixed $responses     The raw response data from Refinitiv API.
     * @param  mixed $marketDetail  Market details mapped by 'refinitiv_universe'.
     * @return array                An array of processed market indicator data.
     */
    public function handleRefSnapshotResponses(mixed $responses, mixed $marketDetail): array
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [];
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $market = isset($res['Key']["Name"]) ? $marketDetail[str_replace('/.', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                output(sprintf(LogMessages::REFINITIV_MISSING_MARKET_INDICATORS, json_encode($res)));
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
                $d = $this->appendDetails($d, $market, $date);
                array_push($refSnapshotIndicatorData, $d);
            }
        }

        return $refSnapshotIndicatorData;
    }

    /**
     * Append market details to data.
     *
     * @param array  $data            Market indicators.
     * @param array  $market          Market details (id, name, universe).
     * @param string $date            Current timestamp ('Y-m-d H:i:s').
     * @param bool   $toJson          Convert market info to JSON (default: true).
     * @return array                   Merged data with market and timestamp.
     */
    public function appendDetails(array $data, array $market, string $date, bool $convertMarketInfoToJson = true): array
    {
        $marketInfo = [
            self::REFINITIV_UNIVERSE => $market[self::REFINITIV_UNIVERSE],
            'market_id' => $market['id'],
            'name' => $market['name']
        ];

        if ($convertMarketInfoToJson) {
            $marketInfo = json_encode($marketInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return array_merge($data, [
            self::REFINITIV_UNIVERSE => $market[self::REFINITIV_UNIVERSE],
            'market_id' => $market['id'],
            'created_at' => isset($market['created_at']) ? $market['created_at'] : $date,
            'updated_at' => $date,
            'market_info' => $marketInfo
        ]);
    }

    /**
     * Save Refinitiv snapshot data asynchronously.
     *
     * @param array  $indicatorsData  Market indicator data.
     * @param string $tableName       Target database table.
     * @return void
     */
    public function saveRefSnapshotDataIntoDBTable(array $indicatorsData, string $tableName): void
    {
        output(sprintf(LogMessages::SAVE_REF_MARKET_SNAPSHOT, $tableName));
        go(function () use ($indicatorsData, $tableName) {
            $dbQuery = $this->makeRefInsertQuery($tableName, $indicatorsData);

            executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
        });
    }

    /**
     * Generates SQL INSERT query for market snapshot data.
     *
     * @param  string $tableName      Target database table.
     * @param  array  $indicatorsData Market snapshot data to insert.
     * @return string                 Generated SQL query string.
     */
    public function makeRefInsertQuery(string $tableName, array $indicatorsData): string
    {
        $dbQuery = "";
        $values = [];

        switch ($tableName) {
            case self::REF_SNAPSHOT_MARKET_TABLE_NAME == $tableName:
                foreach ($indicatorsData as $indicator) {
                    // Collect each set of values into the array
                    $values[] = "(
                        " . $indicator['market_id'] . ",
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
                $dbQuery = "INSERT INTO " . $tableName . " (market_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close,
                low_1, netchng_1, num_moves, open_prc, pctchng, trdprc_1, turnover,
                yrhigh, yrlow, yr_pctch, cf_close, bid, ask, asksize, bidsize, created_at, updated_at)
                VALUES " . implode(", ", $values);
                break;
            default:
                break;
        }

        return $dbQuery;
    }


    /**
     * Compares Refinitiv snapshot responses with existing data.
     *
     * @param  mixed  $responses    Snapshot responses from Refinitiv.
     * @param  mixed  $marketDetail Existing market data for comparison.
     * @param  string $marketName   Name of the market being processed.
     * @return array                Contains changed indicators, records, and status:
     *                              - 'all_changed_indicators': Changed columns.
     *                              - 'all_changed_records': Changed rows.
     *                              - 'is_ref_data': Boolean flag for Refinitiv data presence.
     */
    public function handleRefSnapshotResponsesWithExistingData(mixed $responses, mixed $marketDetail, string $marketName): array
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');
        $marketName .= '_';

        $refSnapshotIndicatorData = [
            self::ALL_CHANGED_INDICATORS => [],
            self::ALL_CHANGED_RECORDS => [],
            self::IS_REF_DATA => false,
        ];

        $isChangedData = false;
        $allChangedIndicators = [];
        $indicator = [];
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $market = isset($res['Key']["Name"]) ? $marketDetail[str_replace('/.', '', $res['Key']["Name"])] : null;

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

                if ($value == $this->floatEmptyValue && !isset($market[$field])) {
                    $d[$field] = $value;
                } else if (
                    $value != $this->floatEmptyValue &&
                    (!isset($market[$field]) ||
                        $value != $market[$field])
                ) {

                    $d[$field] = $value;
                    $indicator[$field] = $value;

                    // Append detail with every indicator
                    $allChangedIndicators[strtolower($market[self::REFINITIV_UNIVERSE]) . '_' . $marketName . $field][] = $this->appendDetails($indicator, $market, $date, false);

                    $isChangedData = true;
                    $refSnapshotIndicatorData[self::IS_REF_DATA] = true;

                    $indicator = []; // Reset the indicator array
                }
            }

            if ($isChangedData) {
                $d = $this->appendDetails($d, $market, $date);
                // All changed Records (Rows)
                $refSnapshotIndicatorData[self::ALL_CHANGED_RECORDS][] = $d;
            }
        }

        if (count($allChangedIndicators) > 0) {
            // All changed Indicators (Columns)
            $refSnapshotIndicatorData[self::ALL_CHANGED_INDICATORS] = $allChangedIndicators;
        }

        return $refSnapshotIndicatorData;
    }

    /**
     * Broadcasts changed indicators to WebSocket workers.
     *
     * @param  array $deltaOfIndicators    Key-value pairs of changed indicators.
     * @param  mixed $mAIndicatorJobRunsAt Timestamp of the job run.
     * @return void
     */
    public function broadcastIndicatorsData(array $deltaOfIndicators, mixed $mAIndicatorJobRunsAt): void
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

    /**
     * Fetch Refinitiv data markets from the database.
     *
     * @param  string $tableName Table name to query.
     * @return mixed             Query result.
     */
    public function fetchOnlyRefDataMarketsWithRefDataFromDB(string $tableName): mixed
    {
        $dbQuery = "SELECT refTable.*, m.name, m.refinitiv_universe
        FROM  $tableName refTable JOIN markets m ON refTable.market_id = m.id ORDER BY refTable.updated_at DESC";

        return executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
    }

    /**
     * Check if Refinitiv data is fresh.
     *
     * @param  array $indicatorDataFromDB Indicator data from the database.
     * @return bool                       True if data is fresh, false otherwise.
     */
    public function isFreshRefData(array $indicatorDataFromDB): bool
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

    /**
     * loadSwooleTableWithRefDataFromDB
     *
     * @param  string $tableName
     * @param  array $indicatorsDBData
     * @param  string $key
     * @return void
     */
    public function loadSwooleTableWithRefDataFromDB(string $tableName, array $indicatorsDBData, string $key): void
    {
        output(sprintf(LogMessages::LOADING_SWOOLE_TABLE, $tableName));
        $marketInfo = "";
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($indicatorsDBData as $indicatorDBRec) {
            $marketInfo = json_encode([
                'market_id' => $indicatorDBRec['market_id'],
                'name' =>  $indicatorDBRec['name'],
                self::REFINITIV_UNIVERSE =>  $indicatorDBRec[self::REFINITIV_UNIVERSE],
            ]);

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
                'market_id' => $indicatorDBRec['market_id'],
                self::REFINITIV_UNIVERSE =>  $indicatorDBRec[self::REFINITIV_UNIVERSE],
                'created_at' =>  $indicatorDBRec['created_at'],
                'updated_at' =>  $indicatorDBRec['updated_at'],
                'market_info' => $marketInfo,
            ];

            $table->set($data[$key], $data);
        }
    }

    /**
     * Load Swoole table with Refinitiv data from the database.
     *
     * @param string $tableName         Name of the Swoole table.
     * @param array  $indicatorsDBData  Indicator data from the database.
     * @param string $key               Key to index the Swoole table.
     * @return void
     */
    public function getAllMarketsWithRefDataFromDB(string $tableName): mixed
    {
        $dbQuery = "SELECT refTable.*, m.name, m.refinitiv_universe
        FROM  $tableName  refTable JOIN markets m ON refTable.market_id = m.id
        WHERE m.refinitiv_universe IS NOT NULL
        AND m.refinitiv_universe NOT LIKE '%^%'
        AND m.refinitiv_universe ~ '^[0-9a-zA-Z\\.]+$'";

        $results =  executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);

        // Process the results: create an associative array with 'refinitiv_universe' as the key and 'id' as the value
        $marketDetail = [];
        foreach ($results as $row) {
            $marketDetail[$row[self::REFINITIV_UNIVERSE]] = $row;
        }

        return $marketDetail;
    }

    /**
     * Update Refinitiv snapshot data into the database.
     *
     * @param array  $indicatorsData  Data to be inserted or updated.
     * @param string $tableName       Target database table.
     * @param string $key             Unique key for conflict resolution.
     * @return void
     */
    public function updateRefSnapshotIntoDBTable(array $indicatorsData, string $tableName, string $key): void
    {
        output(sprintf(LogMessages::UPDATE_DB_TABLE, $tableName));
        go(function () use ($indicatorsData, $tableName, $key) {
            // Fetch all columns dynamically
            $allColumns = array_filter($this->getTableColumns($tableName), function ($column) {
                return $column !== 'id'; // Exclude primary key column
            });

            foreach ($indicatorsData as $indicator) {
                // Provided columns in request (excluding $key)
                $providedColumns = array_keys($indicator);
                $providedColumns = array_diff($providedColumns, [$key]); // Exclude

                // Initialize arrays for columns, values, and updates
                $columns = [$key];  // Always include
                $values = [$indicator[$key]];
                $updates = [];

                // Loop through all table columns
                foreach ($allColumns as $column) {
                    // Skip unnecessary fields
                    if (in_array($column, [$key, 'created_at', 'updated_at', 'market_info'])) {
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
                            ON CONFLICT ($key) DO UPDATE SET " . implode(', ', $updates) . ";";

                executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
            }
        });
    }
}
