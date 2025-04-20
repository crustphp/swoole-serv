<?php

namespace App\Services;

use App\Services\RefSnapshotAPIConsumer;
use DB\DbFacade;
use App\Constants\LogMessages;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Swoole\Coroutine as Co;

class RefSectorDataService
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
    const REF_SNAPSHOT_SECTOR_TABLE_NAME = 'sectors_indicators';
    const IS_REF_DATA = 'is_ref_data';
    const ALL_CHANGED_INDICATORS = 'all_changed_indicators';
    const JOB_RUN_AT = 'jobs_runs_at';
    const RIC = 'ric';
    const MARKET_SECTOR_TABLE_NAME = 'market_sectors';
    const SECTOR_TABLE_UNIQUE_CONSTRAINT = 'sector_id';
    const PREFIX_INDICATOR_TITLE = 'sector_';
    const SECTOR_INFO = 'sector_info';

    const SECTOR_TABLE_COLOMUNS = [
        self::SECTOR_TABLE_UNIQUE_CONSTRAINT => self::SECTOR_TABLE_UNIQUE_CONSTRAINT,
        'name' => 'name',
        self::RIC => self::RIC,
        'name_ar' => 'name_ar',
        'exchange_code' => 'exchange_code',
    ];

    const SECTOR_IDICATORS_TABLE_COLOMUNS = [
        'cf_high' => 'cf_high',
        'cf_last' => 'cf_last',
        'cf_low' => 'cf_low',
        'cf_volume' => 'cf_volume',
        'high_1' => 'high_1',
        'hst_close' => 'hst_close',
        'low_1' => 'low_1',
        'netchng_1' => 'netchng_1',
        'num_moves' => 'num_moves',
        'open_prc' => 'open_prc',
        'pctchng' => 'pctchng',
        'trdprc_1' => 'trdprc_1',
        'turnover' => 'turnover',
        'yrhigh' => 'yrhigh',
        'yrlow' => 'yrlow',
        'yr_pctch' => 'yr_pctch',
        'cf_close' => 'cf_close',
        'bid' => 'bid',
        'ask' => 'ask',
        'asksize' => 'asksize',
        'bidsize' => 'bidsize',
        'sector_id' => 'sector_id',
        'created_at' =>  'created_at',
        'updated_at' =>  'updated_at',
        'sector_info' => 'sector_info',
    ];


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
            $sectorsDetail = null;
            $dataExistInDB = false;
            $dataInitCase = true;

            // Aggregate query to get the count from the Database table
            $dataExistInDB = $this->getDataCountFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);

            if ($dataExistInDB) { // Case: The websocket service not running for the first time in its entirety
                // Get only sectors which has Ref Data from DB.
                $sectorDetailWithRefData = $this->fetchOnlyRefDataSectorsWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);

                // If the data is fresh, initialize from the database
                if ($this->isFreshRefData($sectorDetailWithRefData)) {
                    output(sprintf(LogMessages::REF_SECTOR_RECORD_WITHIN_TIMESPAN, $this->refTimeSpan));

                    go(function () use ($sectorDetailWithRefData) {
                        // Load sectors data into swoole table
                        $this->loadSwooleTableWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME, $sectorDetailWithRefData, self::SECTOR_TABLE_UNIQUE_CONSTRAINT, self::SECTOR_TABLE_COLOMUNS, self::SECTOR_IDICATORS_TABLE_COLOMUNS, self::SECTOR_INFO);

                        // Load job run at into swoole table
                        $this->saveIntoSwooleTable([['job_name' => self::REF_SNAPSHOT_SECTOR_TABLE_NAME, 'job_run_at' => array_shift($sectorDetailWithRefData)['updated_at']]], self::JOB_RUN_AT, 'job_name');
                    });
                } else {
                    output(sprintf(LogMessages::REF_SECTOR_OLDER_DATA_EXISTS, $this->refTimeSpan));
                    go(function () use ($dataExistInDB, $dataInitCase) {
                        // Get all sectors with Ref Data from the database for calculating change or delta.
                        $sectorDetailWithRefData = $this->getAllSectorsWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);
                        // Fetch data from Refinitiv, calculate changes, broadcasting, update in swoole table, and store/update in DB.
                        $isProcessedRefSectorData = $this->processRefData($sectorDetailWithRefData, self::REF_SNAPSHOT_SECTOR_TABLE_NAME, $dataExistInDB, $dataInitCase, self::RIC, self::PREFIX_INDICATOR_TITLE, self::MARKET_SECTOR_TABLE_NAME, self::SECTOR_TABLE_UNIQUE_CONSTRAINT, self::SECTOR_TABLE_COLOMUNS);

                        if (!$isProcessedRefSectorData) {
                            output(sprintf(LogMessages::REFINITIV_NO_SECTOR_DATA, 'delta'));
                        }

                        // Get the sectors which have Ref Data from DB.
                        $sectorDetailWithRefData = $this->fetchOnlyRefDataSectorsWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);
                        // Load into swoole table
                        $this->loadSwooleTableWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME, $sectorDetailWithRefData, self::SECTOR_TABLE_UNIQUE_CONSTRAINT, self::SECTOR_TABLE_COLOMUNS, self::SECTOR_IDICATORS_TABLE_COLOMUNS, self::SECTOR_INFO);
                    });
                }
            } else {
                output(LogMessages::NO_REF_SECTOR_DATA);
                $sectorsDetail = $this->getSectorsFromDB(self::MARKET_SECTOR_TABLE_NAME);

                go(function () use ($sectorsDetail, $dataExistInDB, $dataInitCase) {
                    // Fetch data from Refinitiv, store in swoole table, and store in DB.
                    $isProcessedRefSectorData = $this->processRefData($sectorsDetail, self::REF_SNAPSHOT_SECTOR_TABLE_NAME, $dataExistInDB, $dataInitCase, self::RIC, self::PREFIX_INDICATOR_TITLE, self::MARKET_SECTOR_TABLE_NAME, self::SECTOR_TABLE_UNIQUE_CONSTRAINT, self::SECTOR_TABLE_COLOMUNS);

                    if (!$isProcessedRefSectorData) {
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
     * Initialize Reference Sector Data
     *
     * Checks for existing sector data in the database, retrieves or initializes it,
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
            $dataExistInDB = $this->getDataCountFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);

            if ($dataExistInDB) {
                $dataExistInDB = true;
                $sectorsDetailWithRefData = $this->getAllSectorsWithRefDataFromDB(self::REF_SNAPSHOT_SECTOR_TABLE_NAME);
            } else {
                $dataInitCase = true;
                $sectorsDetailWithRefData = $this->getSectorsFromDB(self::MARKET_SECTOR_TABLE_NAME);
            }

            // Fetch data from Refinitiv, calculate changes, broadcasting, update in swoole table, and store/update in DB.
            $isProcessedRefSectorsData = $this->processRefData($sectorsDetailWithRefData, self::REF_SNAPSHOT_SECTOR_TABLE_NAME, $dataExistInDB, $dataInitCase, self::RIC, self::PREFIX_INDICATOR_TITLE, self::MARKET_SECTOR_TABLE_NAME, self::SECTOR_TABLE_UNIQUE_CONSTRAINT, self::SECTOR_TABLE_COLOMUNS);
            if (!$isProcessedRefSectorsData) {
                output(sprintf(LogMessages::REFINITIV_NO_SECTOR_DATA, $dataExistInDB ? 'delta' : 'data'));
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
     * Retrieve Sectors Data from Database
     *
     * Fetches sector records where 'ric' is valid and not null,
     * then maps 'ric' as the key with corresponding row data.
     *
     * @return array Associative array with 'ric' as keys.
     */
    public function getSectorsFromDB($tableName): mixed
    {
        $dbQuery = "SELECT * FROM $tableName WHERE ric IS NOT NULL AND ric NOT LIKE '%^%' AND ric ~ '^[0-9a-zA-Z\\.]+$'";

        $results = executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
        // Process the results: create an associative array with 'ric' as the key and 'id' as the value
        $sectorDetail = [];
        foreach ($results as $row) {
            $sectorDetail[$row[self::RIC]] = $row;
        }

        return $sectorDetail;
    }

    /**
     * Process Reference Data
     *
     * Fetches, processes, and stores sector data.
     * Updates existing records or initializes new data based on DB state.
     *
     * @param mixed      $sectorDetail     Sector details to process.
     * @param string     $dbTableName      Database table name.
     * @param bool|null  $dataExistInDB    Whether data exists in the DB.
     * @param bool|null  $dataInitCase     Whether it's a fresh initialization.
     * @param string     $columnName       Column for identification.
     * @param string     $prefixIndicatorTitle like 'sector_'.
     * @param string     $sectorTableName  Sector table name.
     * @param string     $dbColumnWithUniqueConstraint  Unique constraint column in Database.
     * @param array      $sectorInfoColumns  Sector's info columns in Database Table.
     *
     * @return bool                         True if successful, false otherwise.
     */
    public function processRefData(mixed $sectorDetail, string $dbTableName, ?bool $dataExistInDB = null, ?bool $dataInitCase = null, string $columnName, string $prefixIndicatorTitle, string $sectorTableName, string $dbColumnWithUniqueConstraint, array $sectorInfoColumns): bool
    {
        $isProcessedRefIndicatorsData = false;
        // Fetch data from Refinitive
        $responses = $this->fetchRefData($sectorDetail, $columnName, $sectorTableName);

        // Handle Refinitive responses
        if ($dataExistInDB) {
            $refIndicatorsData = $this->handleRefSnapshotResponsesWithExistingData($responses, $sectorDetail, $prefixIndicatorTitle, $sectorInfoColumns);
            if (count($refIndicatorsData[self::ALL_CHANGED_INDICATORS]) > 0) {
                // Get the job_run_at value from the Swoole Table
                $jobRunsAtData = getJobRunAt($dbTableName);
                // Broadcasting changed data
                $this->broadcastIndicatorsData($refIndicatorsData[self::ALL_CHANGED_INDICATORS], $jobRunsAtData);
            }

            $processedRecords = $refIndicatorsData[self::ALL_CHANGED_RECORDS];

            if (!$dataInitCase && count($processedRecords) > 0) {
                // Load into the swoole Table
                $this->saveIntoSwooleTable($processedRecords, $dbTableName, $dbColumnWithUniqueConstraint);
            }

            // Save job run at into swoole table
            $this->saveIntoSwooleTable([['job_name' => $dbTableName, 'job_run_at' => isset($processedRecords[0]['updated_at']) ? $processedRecords[0]['updated_at'] : Carbon::now()->format('Y-m-d H:i:s')]], self::JOB_RUN_AT, 'job_name');
            // Update and Insert into DB when there is changed data
            if (count($processedRecords) > 0) {
                // Update/Save into the DB
                $this->updateRefSnapshotIntoDBTable($processedRecords, $dbTableName, $dbColumnWithUniqueConstraint);
            }

            // Is Refinitive Data Received
            $isProcessedRefIndicatorsData = $refIndicatorsData[self::IS_REF_DATA];
        } else {
            $refIndicatorsData = $this->handleRefSnapshotResponses($responses, $sectorDetail, $sectorInfoColumns);

            // Initialize fresh data from Refinitiv for indicators
            if (count($refIndicatorsData) > 0) {
                output(LogMessages::INITIALIZE_SECTOR_REFINDICATORS);
                // Save into swoole table
                $this->saveIntoSwooleTable($refIndicatorsData, $dbTableName, $dbColumnWithUniqueConstraint);
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

            $sectorDataExist = $table->get($data[$key]);
            // To Check a sector data exist already
            if (empty($sectorDataExist)) {
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
     * Fetch Sector Data from Refinitiv
     *
     * Retrieves sector data from the Refinitiv API for specified sectors.
     *
     * @param  mixed  $sectorDetail  Sector details to fetch data for.
     * @param  string $columnName    Column name for identification.
     * @param  string $tableName     Database table name.
     * @return mixed                 API response data.
     */
    public function fetchRefData(mixed $sectorDetail, string $columnName, string $tableName): mixed
    {
        $service = new RefSnapshotAPIConsumer($this->server, $this->objDbPool, $this->dbFacade, config('ref_config.ref_pricing_snapshot_url'), $this->refTokenLock);
        $responses = $service->handle($sectorDetail, $this->fields, $columnName, $tableName);
        unset($service);

        return $responses;
    }

    /**
     * Process Refinitiv Snapshot Responses
     *
     * Handles and processes snapshot responses from Refinitiv, extracting relevant sector indicator data.
     *
     * @param  mixed $responses     The raw response data from Refinitiv API.
     * @param  mixed $sectorDetail  Sector details mapped by 'refinitiv_universe'.
     * @param array  $sectorInfoColumns  sector tables columns which are used as common attributes.
     * @return array                An array of processed sector indicator data.
     */
    public function handleRefSnapshotResponses(mixed $responses, mixed $sectorDetail, array $sectorInfoColumns): array
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

        $refSnapshotIndicatorData = [];
        $fields = explode(',', $this->fields);

        foreach ($responses as $res) {
            $sector = isset($res['Key']["Name"]) ? $sectorDetail[str_replace('/.', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                output(sprintf(LogMessages::REFINITIV_MISSING_SECTOR_INDICATORS, json_encode($res)));
                continue;
            }

            $indicatorsRecord = [];
            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = $res['Fields'][$field] ?? $this->floatEmptyValue;
                $field = strtolower($field);

                $indicatorsRecord[$field] = $value;
            }

            if (!empty($indicatorsRecord)) {
                $indicatorsRecord = $this->appendDetails($indicatorsRecord, $sector, $date, $sectorInfoColumns);
                array_push($refSnapshotIndicatorData, $indicatorsRecord);
            }
        }

        return $refSnapshotIndicatorData;
    }

    /**
     * Append sector details to data.
     *
     * @param array  $data              Sector indicators.
     * @param array  $sector            Sector details (id, name, universe).
     * @param string $date              Current timestamp ('Y-m-d H:i:s').
     * @param array  $sectorInfoColumns Sector tables columns which are used as common attributes.
     * @param bool   $toJson            Convert Sector info to JSON (default: true).
     * @return array                    Merged data with Sector and timestamp.
     */
    public function appendDetails(array $data, array $sector, string $date, array $sectorInfoColumns, bool $convertSectorInfoToJson = true): array
    {
        $sectorInfo =  $this->mapKeysFromRecord($sector, $sectorInfoColumns);

        if ($convertSectorInfoToJson) {
            $sectorInfo = json_encode($sectorInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return array_merge($data, [
            'sector_id' => $sector['id'],
            'created_at' => isset($sector['created_at']) ? $sector['created_at'] : $date,
            'updated_at' => $date,
            'sector_info' => $sectorInfo
        ]);
    }

    /**
     * Save Refinitiv snapshot data asynchronously.
     *
     * @param array  $indicatorsData  Sector indicator data.
     * @param string $tableName       Target database table.
     * @return void
     */
    public function saveRefSnapshotDataIntoDBTable(array $indicatorsData, string $tableName): void
    {
        output(sprintf(LogMessages::SAVE_REF_SECTOR_SNAPSHOT, $tableName));
        go(function () use ($indicatorsData, $tableName) {
            $dbQuery = $this->makeRefInsertQuery($tableName, $indicatorsData);
            executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);
        });
    }

    /**
     * Generates SQL INSERT query for sector snapshot data.
     *
     * @param  string $tableName      Target database table.
     * @param  array  $indicatorsData Sector snapshot data to insert.
     * @return string                 Generated SQL query string.
     */
    public function makeRefInsertQuery(string $tableName, array $indicatorsData): string
    {
        $dbQuery = "";
        $values = [];

        switch ($tableName) {
            case self::REF_SNAPSHOT_SECTOR_TABLE_NAME == $tableName:
                foreach ($indicatorsData as $indicator) {
                    // Collect each set of values into the array
                    $values[] = "(
                        " . $indicator['sector_id'] . ",
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
                $dbQuery = "INSERT INTO " . $tableName . " (sector_id, cf_high, cf_last, cf_low, cf_volume, high_1, hst_close,
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
     * @param  mixed  $sectorDetail Existing sector data for comparison.
     * @param  string $preFixIndicatorTitle   Name of the sector being processed.
     * @param array   $sectorInfoColumns  sector tables columns which are used as common attributes.
     *
     * @return array                Contains changed indicators, records, and status:
     *                              - 'all_changed_indicators': Changed columns.
     *                              - 'all_changed_records': Changed rows.
     *                              - 'is_ref_data': Boolean flag for Refinitiv data presence.
     */
    public function handleRefSnapshotResponsesWithExistingData(mixed $responses, mixed $sectorDetail, string $prefixIndicatorTitle, array $sectorInfoColumns): array
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');

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
            $sector = isset($res['Key']["Name"]) ? $sectorDetail[str_replace('/.', '', $res['Key']["Name"])] : null;

            if (!isset($res['Fields'])) {
                // This code will be saved into DB in the Refinement PR
                output(sprintf(LogMessages::REFINITIV_MISSING_SECTOR_INDICATORS, json_encode($res)));
                continue;
            }

            $indicatorsRecord = [];
            $isChangedData = false;

            // Dyanmically process the fields
            foreach ($fields as $field) {
                $value = $res['Fields'][$field] ?? $this->floatEmptyValue;
                $field = strtolower($field);

                if ($value == $this->floatEmptyValue && !isset($sector[$field])) {
                    $indicatorsRecord[$field] = $value;
                } else if (
                    $value != $this->floatEmptyValue &&
                    (!isset($sector[$field]) ||
                        $value != $sector[$field])
                ) {

                    $indicatorsRecord[$field] = $value;
                    $indicator[$field] = $value;

                    // Append detail with every indicator
                    $allChangedIndicators[$prefixIndicatorTitle . $field][] = $this->appendDetails($indicator, $sector, $date, $sectorInfoColumns, false);

                    $isChangedData = true;
                    $refSnapshotIndicatorData[self::IS_REF_DATA] = true;

                    $indicator = []; // Reset the indicator array
                }
            }

            if ($isChangedData) {
                $indicatorsRecord = $this->appendDetails($indicatorsRecord, $sector, $date, $sectorInfoColumns);
                // All changed Records (Rows)
                $refSnapshotIndicatorData[self::ALL_CHANGED_RECORDS][] = $indicatorsRecord;
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
     * Fetch Refinitiv data sectors from the database.
     *
     * @param  string $tableName Table name to query.
     * @return mixed             Query result.
     */
    public function fetchOnlyRefDataSectorsWithRefDataFromDB(string $tableName): mixed
    {
        $dbQuery = "SELECT refTable.*, ms.name, ms.ric, ms.name_ar, ms.exchange_code
        FROM $tableName refTable JOIN market_sectors ms ON refTable.sector_id = ms.id ORDER BY refTable.updated_at DESC";

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
        // Check if the latest update is older
        if ($latestUpdate->diffInSeconds(Carbon::now()) < $this->refTimeSpan) {
            $isFreshDBData = true;
        }

        return $isFreshDBData;
    }

    /**
     * loadSwooleTableWithRefDataFromDB
     *
     * @param string $tableName
     * @param array $indicatorsDBData
     * @param string $key
     * @param array $sectorColumns
     * @param array $sectorIndicatorsColumns
     * @param string $sectorInfoKey
     * @return void
     */
    public function loadSwooleTableWithRefDataFromDB(string $tableName, array $indicatorsDBData, string $key, array $sectorColumns, array $sectorIndicatorsColumns, string $sectorInfoKey): void
    {
        output(sprintf(LogMessages::LOADING_SWOOLE_TABLE, $tableName));
        $sectorInfo = "";
        $table = SwooleTableFactory::getTable($tableName);
        foreach ($indicatorsDBData as $indicatorDBRec) {
            // Sector info columns
            $sectorInfo = json_encode($this->mapKeysFromRecord($indicatorDBRec, $sectorColumns));

            // Sector swoole table's columns
            $indicatorsData = $this->mapKeysFromRecord(array_merge($indicatorDBRec, [$sectorInfoKey => $sectorInfo]), $sectorIndicatorsColumns);

            $table->set($indicatorsData[$key], $indicatorsData);
        }
    }


    /**
     * getAllSectorsWithRefDataFromDB
     *
     * @param  string $tableName Database table name
     * @return mixed
     */
    public function getAllSectorsWithRefDataFromDB(string $tableName): mixed
    {
        $dbQuery = "SELECT refTable.*, ms.name, ms.ric, ms.exchange_code, ms.name_ar
        FROM  $tableName  refTable JOIN market_sectors ms ON refTable.sector_id = ms.id
        WHERE ms.ric IS NOT NULL
        AND ms.ric NOT LIKE '%^%'
        AND ms.ric ~ '^[0-9a-zA-Z\\.]+$'";

        $results =  executeDbFacadeQueryWithChannel($dbQuery, $this->objDbPool, $this->dbFacade);

        // Process the results: create an associative array with 'ric' as the key and 'id' as the value
        $sectorDetail = [];
        foreach ($results as $row) {
            $sectorDetail[$row[self::RIC]] = $row;
        }

        return $sectorDetail;
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
                    if (in_array($column, [$key, 'created_at', 'updated_at', 'sector_info'])) {
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

    /**
     * mapKeysFromRecord
     *
     * @param  array $sourceArray
     * @param  array $keyMap
     * @return array
     */
    public function mapKeysFromRecord(array $sourceArray, array $keyMap): array
    {
        $mapped = [];
        foreach ($keyMap as $outputKey => $inputKey) {
            if (isset($sourceArray[$inputKey])) {
                $mapped[$outputKey] = $sourceArray[$inputKey];
            }
        }
        return $mapped;
    }
}
