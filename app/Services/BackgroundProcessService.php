<?php

namespace App\Services;

use Swoole\Process;
use DB\DBConnectionPool;

use Swoole\Timer as swTimer;
use App\Services\MostActiveRefinitive;
use DB\DbFacade;
use Carbon\Carbon;
use Bootstrap\SwooleTableFactory;
use Small\SwooleDb\Selector\TableSelector;
use Swoole\Coroutine\Barrier;

class BackgroundProcessService
{
    protected $server;
    protected $dbConnectionPools;
    protected $postgresDbKey;

    public function __construct($server, $postgresDbKey = null) {
        $this->server = $server;
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $this->postgresDbKey = $postgresDbKey ?? $swoole_pg_db_key;
    }

    /**
     * Method to handle background process
     *
     * @return void
     */
    public function handle()
    {
        $refinitivBackgroundProcess = new Process(function ($process) {
            /// DB connection
            $app_type_database_driven = config('app_config.app_type_database_driven');
            $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
            $worker_id = $process->id;

            if ($app_type_database_driven) {
                $poolKey = makePoolKey($worker_id, 'postgres');
                try {
                    // initialize an object for 'DB Connections Pool'; global only within scope of a Worker Process
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key] = new DBConnectionPool($poolKey, 'postgres', 'swoole', true);
                    $this->dbConnectionPools[$worker_id][$swoole_pg_db_key]->create();
                } catch (\Throwable $e) {
                    echo $e->getMessage() . PHP_EOL;
                    echo $e->getFile() . PHP_EOL;
                    echo $e->getLine() . PHP_EOL;
                    echo $e->getCode() . PHP_EOL;
                    var_dump($e->getTrace());
                }
            }

            $objDbPool = $this->dbConnectionPools[$worker_id][$swoole_pg_db_key];

            $dbQuery = "SELECT ric, id, name, sp_comp_id, short_name, symbol, isin_code, created_at, arabic_name, arabic_short_name  FROM companies
            WHERE ric IS NOT NULL
            AND ric NOT LIKE '%^%'
            AND ric ~ '^[0-9a-zA-Z\\.]+$'";

            // Assuming $dbFacade is an instance of DbFacade and $objDbPool is your database connection pool
            $dbFacade = new DbFacade();
            $results = $dbFacade->query($dbQuery, $objDbPool);

            // Process the results: create an associative array with 'ric' as the key and 'id' as the value
            $companyDetail = [];
            foreach ($results as $row) {
                $companyDetail[$row['ric']] = $row;
            }

            // Check data exist into DB table
            $dbQuery = "SELECT rtg.*, c.name AS en_long_name, c.sp_comp_id, c.short_name AS en_short_name, c.symbol,
            c.isin_code, c.created_at, c.arabic_name AS ar_long_name, c.arabic_short_name AS ar_short_name, c.ric
            FROM ref_top_gainers rtg JOIN companies c ON rtg.company_id = c.id";
            $dbTopgGinersData = $dbFacade->query($dbQuery, $objDbPool);

            // Check if a record exists
            if (!empty($dbTopgGinersData)) {
                // Fetch the first record
                $latestTopGainer = $dbTopgGinersData[0];
                $table = SwooleTableFactory::getTable('ref_top_gainers');

                // Parse the latest_update timestamp
                $latestUpdate = Carbon::parse($latestTopGainer['latest_update']);

                // Check if the latest update is more than 5 minutes old
                if ($latestUpdate->diffInMinutes(Carbon::now()) >= 5) {
                    // Record is older than 5 minutes, trigger the update process
                    var_dump("Record is older than 5 minutes. Updating data...");
                    $this->mostActive($worker_id, $companyDetail, $objDbPool, $dbFacade);
                } else {
                    // Proceed with processing the data since it's within the last 5 minutes
                    foreach ($dbTopgGinersData as $key => $topGainer) {
                        $data = [
                            'calculated_value' => $topGainer['calculated_value'],
                            'latest_value' => $topGainer['latest_value'],
                            'latest_update' => $topGainer['latest_update'],
                            'company_id' => $topGainer['company_id'],

                            'en_long_name' =>  $topGainer['en_long_name'],
                            'sp_comp_id' =>  $topGainer['sp_comp_id'],
                            'en_short_name' =>  $topGainer['en_short_name'],
                            'symbol' =>  $topGainer['symbol'],
                            'isin_code' =>  $topGainer['isin_code'],
                            'created_at' =>  $topGainer['created_at'],
                            'ar_long_name' =>  $topGainer['ar_long_name'],
                            'ar_short_name' =>  $topGainer['ar_short_name'],
                            'ric' =>  $topGainer['ric'],
                        ];

                        var_dump("Record is within the last 5 minutes. Data prepared.");
                        go(function () use ($key, $data, $table) {
                            $table->set($key, $data);
                        });
                    }
                }
            } else { // If data not exist into database
                var_dump("Data not found in DB");
                $this->mostActive($worker_id, $companyDetail, $objDbPool, $dbFacade);
            }

            // Schedule of Most Active Data Fetching
            swTimer::tick(config('app_config.most_active_refinitive_timespan'), function () use ($worker_id, $companyDetail, $objDbPool, $dbFacade) {
                $this->mostActive($worker_id, $companyDetail, $objDbPool, $dbFacade);
            });
        }, false, SOCK_DGRAM, true);

        $this->server->addProcess($refinitivBackgroundProcess);
    }

    /**
     * Most active data processing
     *
     * @param  int  $worker_id
     * @param  array  $companyDetail
     * @param  Object  $objDbPool
     * @param  Object  $dbFacade
     *
     * @return void
     */

    public function mostActive(int $worker_id, array $companyDetail, Object $objDbPool, Object $dbFacade) {
        $service = new MostActiveRefinitive($this->server, $objDbPool);
        $responses = $service->handle();
        unset($service);
        $date = Carbon::now()->format('Y-m-d H:i:s');
        $topGainers = [];
        $mostActiveValues = [];
        $doneDealCounts = [];
        $frequentlyTradedStocks = [];

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
                ];
                $topGainers[] = $data;
            }

            if (!empty($res) && isset($res['Fields']["TURNOVER"])) {  // Most Active Value
                $data = [
                    'turnover' => $this->formatValue($res['Fields']["TURNOVER"]),
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,
                ];
                $mostActiveValues[] = $data;
            }

            if (!empty($res) && isset($res['Fields']["NUM_MOVES"])) {  // Deal Done count
                $data = [
                    'count' => $res['Fields']["NUM_MOVES"],
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,

                ];
                $doneDealCounts[] = $data;
            }

            if (!empty($res) && isset($res['Fields']["CF_VOLUME"])) {  // Traded Stock
                $data = [
                    'traded_volume' => $this->formatValue($res['Fields']["CF_VOLUME"]),
                    'latest_value' => isset($res['Fields']["TRDPRC_1"]) ? $res['Fields']["TRDPRC_1"] : null,
                    'calculated_value' => isset($res['Fields']["PCTCHNG"]) ?  $res['Fields']["PCTCHNG"] : null,
                    'company_id' => $company['id'],
                    'latest_update' => $date,
                ];
                $frequentlyTradedStocks[] = $data;
            }
        }

        if(count($topGainers) === 0) {
            var_dump(' Data not received from Refinitiv Pricing Snapshot API');
            return;
        }

        $this->storeInToDBAndSwooleTable($topGainers, 'ref_top_gainers', 'calculated_value', $objDbPool, $dbFacade); // Top Gainers
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

    public function storeInToDBAndSwooleTable(array $mostActiveData, String $tableName, String $column, Object $objDbPool, Object $dbFacade)
    {
        $selector = new TableSelector($tableName);
        $previousMostActiveData = $selector->execute();
        // Convert the result to an array
        $previousMostActiveData = $previousMostActiveData->toArray();
        $previousDataBerrier = Barrier::make();
        $dataIntoArray = [];
        foreach ($previousMostActiveData as $record) { // Map object data into array
            go(function () use ($mostActiveData, $tableName, $record, &$dataIntoArray, $previousDataBerrier) {
                $recordData = [];
                if (count($mostActiveData[0]) > 0) {
                    foreach ($mostActiveData[0] as $key => $value) {
                        $recordData[$key] = $record[$tableName]->getValue($key);
                    }
                }
                $dataIntoArray[] =  $recordData;
            });
        }
        Barrier::wait($previousDataBerrier);

        if (count($dataIntoArray) > 0) {
            var_dump('Data fetched from swoole table ' . $tableName);
            $previousMostActiveData = $dataIntoArray;
        }

        // Check data exist into swoole table
        if (count($previousMostActiveData) < 1) {
            $dbQuery = "SELECT * FROM " . $tableName;
            $previousMostActiveData = $dbFacade->query($dbQuery, $objDbPool);
            var_dump('Data fetched from DB table '.$tableName);
        }

        if (count($previousMostActiveData) > 0) {
            // Compare with existing data
            $differentMostActiveData = $this->compare($previousMostActiveData, $mostActiveData, $column);

            // Broadcast the changed data
            foreach ($differentMostActiveData as $data) {
                go(function () use ($data) {
                    for ($worker_id = 0; $worker_id < $this->server->setting['worker_num']; $worker_id++) {
                        $this->server->sendMessage(json_encode($data), $worker_id);
                    }
                });
            }
        } else {
            var_dump('No changed data for broadcasting data of '.$tableName);
            $differentMostActiveData = $mostActiveData;
        }

        // Difference in prevous and current data then save into DB and swoole table
        if (count($differentMostActiveData) > 0) {
            // Save into swoole table
            $descOrderMostActiveData = $mostActiveData;
            usort($descOrderMostActiveData, function($a, $b) {
                return $b['calculated_value'] <=> $a['calculated_value'];
            });
            var_dump('Save data into Swoole table '.$tableName);
            $table = SwooleTableFactory::getTable($tableName);
            foreach ($descOrderMostActiveData as $key => $mostActive) {
                go(function () use ($key, $mostActive, $table) {
                    $table->set($key, $mostActive);
                });
            }
            // Save into DB
            var_dump('Save data into DB table '.$tableName);
            go(function () use ($dbFacade, $mostActiveData, $tableName, $objDbPool) {
                $dbQuery = 'TRUNCATE TABLE ONLY public."' . $tableName . '" RESTART IDENTITY RESTRICT;';
                $dbQuery .= $this->makeInsertQuery($tableName, $mostActiveData);
                $dbFacade->query($dbQuery, $objDbPool, null, true, true, $tableName);
            });
        }
    }

    /**
     * Compare two arrays of most active values to identify differences and new entries.
     *
     * @param array $previousMostActiveValues Array of associative arrays representing previous most active values.
     * @param array $mostActiveValues Array of associative arrays representing current most active values.
     * @param string $column string has name of column.
     *
     * @return array Array of associative arrays representing most active values that are either different or newly added.
     */
    public function compare(array $previousMostActiveValues, array $mostActiveValues, string $column): array
    {
        $differentMostActiveValues = [];
        $compareDataBerrier = Barrier::make();
        foreach ($previousMostActiveValues as $previousMostActiveValue) {
            go(function () use ($previousMostActiveValue, $mostActiveValues, $column, &$differentMostActiveValues, $compareDataBerrier) {
                foreach ($mostActiveValues as $mostActiveValue) {
                    if ($previousMostActiveValue['company_id'] === $mostActiveValue['company_id']) {
                        if ($this->formatValue((float)$previousMostActiveValue[$column]) != $this->formatValue($mostActiveValue[$column])) {
                            $differentMostActiveValues[] = $mostActiveValue;
                        }
                    }
                }
            });
        }

        foreach ($mostActiveValues as $mostActiveValue) {
            go(function () use ($previousMostActiveValues, $mostActiveValue, &$differentMostActiveValues, $compareDataBerrier) {
                $found = false;
                foreach ($previousMostActiveValues as $previousMostActiveValue) {
                    if ($previousMostActiveValue['company_id'] === $mostActiveValue['company_id']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $differentMostActiveValues[] = $mostActiveValue;
                }
            });
        }

        Barrier::wait($compareDataBerrier);
        var_dump('compare data here ');
        return $differentMostActiveValues;
    }

    public function makeInsertQuery(string $tableName, array $mostActiveData)
    {
        $dbQuery = "";
        switch ($tableName) {
            case 'ref_top_gainers':
                foreach ($mostActiveData as $mostActive) {
                    // Collect each set of values into the array
                    $values[] = "('" . $mostActive['calculated_value'] . "', '" . $mostActive['latest_value'] . "', '" . $mostActive['latest_update'] . "', '" . $mostActive['company_id'] . "')";
                }
                $dbQuery = "INSERT INTO " . $tableName . " (calculated_value, latest_value, latest_update, company_id)
                VALUES " . implode(", ", $values);
                break;
            default:
                break;
        }

        return $dbQuery;
    }

}
