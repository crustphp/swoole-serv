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

class BackgroundProcessService
{
    protected $server;
    protected $dbConnectionPools;
    protected $postgresDbKey;

    protected $tempCounter = 0;

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

            swTimer::tick(config('app_config.most_active_refinitive_timespan'), function () use ($worker_id, $companyDetail, $objDbPool, $dbFacade) {
                $service = new MostActiveRefinitive($this->server, $this->dbConnectionPools[$worker_id]);
                $responses = $service->handle();
                $date = Carbon::now();
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

                // Save top gainers data in swoole table and DB
                $dbQuery = 'TRUNCATE TABLE ONLY public.ref_top_gainers RESTART IDENTITY RESTRICT;';
                $dbFacade->query($dbQuery, $objDbPool);

                $table = SwooleTableFactory::getTable('ref_top_gainers');

                foreach ($topGainers as $key => $topGainer) {
                    // Save in swoole table
                    go(function () use ($topGainer, $table, $key,) {
                        $table->set($key, $topGainer);
                    });

                     // Save in DB
                    go(function () use ($topGainer, $objDbPool, $dbFacade,) {
                        $dbQuery = "INSERT INTO ref_top_gainers (calculated_value, latest_value, latest_update, company_id)
                    VALUES ('" . $topGainer['calculated_value'] . "', '" . $topGainer['latest_value'] . "', '" . $topGainer['latest_update'] . "', '" . $topGainer['company_id'] . "')";
                        $dbFacade->query($dbQuery, $objDbPool);
                    });
                }

                // Compare fetched data with existing data
                // if there is found difference in comparison then Broadcast data
                // Save into Database tables
                // Save into swoole table for cache purpose

                // for ($i = 0; $i < $this->server->setting['worker_num']; $i++) {
                //     $message = 'From Backend | For Worker: ' . $i;
                //     $this->server->sendMessage($message, $i);
                // }

            });
        }, false, SOCK_DGRAM, true);

        $this->server->addProcess($refinitivBackgroundProcess);
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

}
