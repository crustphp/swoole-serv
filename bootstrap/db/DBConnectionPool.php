<?php

namespace DB;

use http\Exception\RuntimeException;
use Swoole\Coroutine\PostgreSQL;
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Smf\ConnectionPool\Connectors\PDOConnector;
use DB\ConnectionPoolTrait;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Coroutine;

use OpenSwoole\Core\Coroutine\Client\PostgresClientFactory as oswPostgresClientFactory;
use OpenSwoole\Core\Coroutine\Client\PostgresConfig as oswPostgresConfig;
use OpenSwoole\Core\Coroutine\Pool\ClientPool as oswClientPool;

use DB\SwoolePgConfig;
use DB\SwoolePgConnectionPool;

class DBConnectionPool
{
    use ConnectionPoolTrait;

    protected $pool_key;
    protected $isPdo;
    protected string $dbEngine = 'postgres';
    protected string $poolDriver = 'swoole';
    protected $swoole_ext;

    // Number of Connections in Each Pool
    protected $poolSize = 3;

    // Worker Related Properties
    protected $workerId;
    protected $totalEventWorkers;

    function __construct($pool_key, string $dbEngine, $poolDriver='smf', bool $isPdo = true) {
        $dbEngine = strtolower($dbEngine);
        if ($dbEngine != 'postgres' && $dbEngine != 'mysql') {
            throw new \RuntimeException('In DBConnectionPool Constructor: the value of $dbEngine should either be \'postgres\' or \'mysql\'');
        }
        $poolDriver = strtolower($poolDriver);
        if ($poolDriver != 'smf' && $poolDriver != 'swoole' && $poolDriver!= 'openswoole') {
            throw new \RuntimeException('In DBConnectionPool Constructor: the value of $poolDriver should either be \'smf\' or \'swoole\' or \'openswoole\'');
        }
        $this->poolDriver = $poolDriver;
        $this->dbEngine = $dbEngine;
        $this->isPdo = $isPdo;
        $this->pool_key = $pool_key;
        $this->swoole_ext = config('app_config.swoole_ext');

        // Set the Default Pool Size
        $this->workerId = getIdFromDbPoolKey($pool_key, $dbEngine);

        $this->totalEventWorkers = config('swoole_config.server_settings.worker_num');
        if (is_null($this->totalEventWorkers)) {
            throw new \RuntimeException('Error: Please add number of Event Worker in Swoole Config inside server_settings as worker_num');
        }

        // Set the value of Pool Size based on if its for Event Worker or Custom User Process
        if ($this->workerId < $this->totalEventWorkers) {
            $this->poolSize = config('db_config.event_workers_db_connection_pool_size');
        }
        else {
            $this->poolSize = config('db_config.custom_processes_db_connection_pool_size');
        }

    }

    function __destruct() {
        if (isset(self::$pools[$this->pool_key])) {
            $this->closeConnectionPool($this->pool_key);
        }
    }

    /**
     * create
     *
     * @param  mixed $shouldFill
     * @return void
     */
    public function create(bool $shouldFill = false) {
        $swPostgresServerHost = config('db_config.sw_postgres_server_host');
        $swPostgresServerPort = config('db_config.sw_postgres_server_port');
        $swPostgresServerDB = config('db_config.sw_postgres_server_db');
        $swPostgresServerUser = config('db_config.sw_postgres_server_user');
        $swPostgresServerPasswd = config('db_config.sw_postgres_server_passwd');

        $swMysqlServerDriver = config('db_config.sw_mysql_server_driver');
        $swMysqlServerHost = config('db_config.sw_mysql_server_host');
        $swMysqlServerPort = config('db_config.sw_mysql_server_port');
        $swMysqlServerDb = config('db_config.sw_mysql_server_db');
        $swMysqlServerUser = config('db_config.sw_mysql_server_user');
        $swMysqlServerCharset = config('db_config.sw_mysql_server_charset');
        $swMysqlServerPasswd = config('db_config.sw_mysql_server_passwd');

        // Create Pool object, and configure Pool object with Database
        $obj_conn_pool = $this->create_connection_pool_object(
            $swPostgresServerHost,
            $swPostgresServerPort,
            $swPostgresServerDB,
            $swPostgresServerUser,
            $swPostgresServerPasswd,
            $this->isPdo,
            $this->poolDriver,
            $this->dbEngine
        );

        // Actually create Database Connections, and fill the Pool with those connections if $shouldFill true
        // Otherwise connections will be created on demand.
        if ($shouldFill) {
            $this->fill_pool($obj_conn_pool);
        }

        // Key to access Connection Pool; through ConnectionPoolTrait
        $this->add_pool_with_key($obj_conn_pool, $this->pool_key);
    }

    protected function create_connection_pool_object($serverHost, $serverPort, $serverDB, $serverUser, $serverPasswd,
                                                  bool $isPdo = true, string $poolDriver='smf', $dbEngine = 'postgres')
    {
        $poolDriver = strtolower($poolDriver);
        if ($dbEngine == 'postgres') {
            if ($poolDriver == 'smf') {
                // All PosgreSQL connections: [4 workers * 2 = 8, 4 workers * 10 = 40]
                return new ConnectionPool(
                    [
                        'minActive' => 10,
                        'maxActive' => 30,
                        'maxWaitTime' => 5,
                        'maxIdleTime' => 20,
                        'idleCheckInterval' => 10,
                    ],
                    (($isPdo) ? new PDOConnector : new CoroutinePostgreSQLConnector),
                    (($isPdo) ?
                        [
                            'dsn' => 'pgsql:host='.$serverHost.';
                                        port='.$serverPort.';
                                        dbname='.$serverDB,
                            'username' => $serverUser,
                            'password' => $serverPasswd,
                            'options' => [
                                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                                \PDO::ATTR_TIMEOUT => 30,
                            ],
                        ] :
                        [
                            'connection_strings' => 'host='.$serverHost.';
                                            port='.$serverPort.';
                                            dbname='.$serverDB.';
                                            user='.$serverUser.';
                                            password='.$serverPasswd,
                        ]
                    )
                );
            } else if ($poolDriver == 'swoole' || $poolDriver == 'openswoole') {
                if ($this->swoole_ext = 1) {
                    $config = (new SwoolePgConfig())
                        ->withHost($serverHost)
                        ->withPort($serverPort)
                        ->withDbname($serverDB)
                        ->withUsername($serverUser)
                        ->withPassword($serverPasswd);
                    return new SwoolePgConnectionPool($config, $this->poolSize);
                } else if ($this->swoole_ext = 2) {
                    $config = (
                    (new oswPostgresConfig())
                        ->withHost($serverHost)
                        ->withPort($serverPort)
                        ->withDbname($serverDB)
                        ->withUsername($serverUser)
                        ->withPassword($serverPasswd)
                    );
                    return new oswClientPool(oswPostgresClientFactory::class, $config, $this->poolSize);
                } else {
//                    throw new \Swoole\ExitException("Swoole Extension Not Found");
                    throw new \RuntimeException("Swoole Extension Not Found.".PHP_EOL." File: ".__FILE__.PHP_EOL." Line: ".__LINE__);
                }
            }
        } else {
            if (!empty($swMysqlServerDriver)) {
                $db_prefix = 'MYSQL_'; // POSTGRES_
                return new PDOPool(
                    (new PDOConfig())
                        ->withDriver('mysql')
                        ->withHost(constant($db_prefix . 'SERVER_HOST'))
                        ->withPort(constant($db_prefix . 'SERVER_PORT'))
                        // ->withUnixSocket('/tmp/mysql.sock')
                        ->withDbName(constant($db_prefix . 'SERVER_DB'))
                        //->withCharset('')
                        ->withUsername(constant($db_prefix . 'SERVER_USER'))
                        ->withPassword(constant($db_prefix . 'SERVER_PWD'))
                );
            } else {
                throw new \http\Exception\RuntimeException("Swoole's PDO Coroutine client supports MySQL only");
            }
        }
    }

    public function fill_pool($obj_conn_pool){
        // Creates a Connection Pool (Channel) of Connections
        if ($this->poolDriver == 'smf') {
            // Creates a Connection Pool (Channel) of Connections
            $obj_conn_pool->init();
        } else if ($this->poolDriver == 'swoole' || $poolDriver=='openSwoole') {
            $obj_conn_pool->fill();
        } else {
            throw new RuntimeException('');
        }
    }

    public function add_pool_with_key($obj_conn_pool, $poolKey = null) {
        $this->addConnectionPool($poolKey ?? $this->pool_key, $obj_conn_pool);
    }

    /**
     * Returns the Connection Pool object by given key
     *
     * @param  string $pool_key
     * @return mixed
     */
    public function get_connection_pool_with_key(string $pool_key = null) {
        return $this->getConnectionPool(is_null($pool_key) ? $this->pool_key : $pool_key);
    }

    public function get_dbObject_from_connection_pool($objConnectionPool) {
        $poolDriver = $this->poolDriver;
        if ($poolDriver == 'smf') {
            // Creates a Connection Pool (Channel) of Connections
            return $objConnectionPool->borrow();
        } else if ($poolDriver == 'swoole' || $poolDriver=='openSwoole') {
            // For swoole $objConnectionPool is of type SwoolePgConnectionPool, and ...
            // for OpenSwoole it is of type OpenSwoole\Core\Coroutine\Pool\ClientPool
            // For Swoole get() returns new Swoole\Coroutine\PostgreSQL();
            // For OpenSwoole get() returns new OpenSwoole\Coroutine\PostgreSQL();
            return $objConnectionPool->get();
        }
    }

    /*
    * This function will return a single connection (object) from connection pool
    * @param  string $pool_key
    * @return mixed
    */
    public function get_dbConnectionObj_using_pool_key($pool_key=null) {
        if (is_null($pool_key)) {
            $pool_key = $this->pool_key;
        }

        $objConnectionPool = $this->getConnectionPool($pool_key);
        $poolDriver = $this->poolDriver;
        if ($poolDriver == 'smf') {
            // Creates a Connection Pool (Channel) of Connections
            return $objConnectionPool->borrow();
        } else if ($poolDriver == 'swoole' || $poolDriver=='openSwoole') {
            // For swoole $objConnectionPool is of type SwoolePgConnectionPool, and ...
            // for OpenSwoole it is of type OpenSwoole\Core\Coroutine\Pool\ClientPool
            // For Swoole get() returns new Swoole\Coroutine\PostgreSQL();
            // For OpenSwoole get() returns new OpenSwoole\Coroutine\PostgreSQL();

            // Check if the code is executed inside coroutine context
            if (Coroutine::getCid() == -1) {
                throw new \RuntimeException(__METHOD__ . ' Should be called in the coroutine context');
            }

            return $objConnectionPool->get();
        }
    }

    /**
     * This function returns replaced database Client object
     *
     * @param  mixed $pool_key Optional pool key
     * @return mixed
     */
    public function get_replaced_dbObject($pool_key = null) {
        if (is_null($pool_key)) {
            $pool_key = $this->pool_key;
        }

        $objConnectionPool = $this->getConnectionPool($pool_key);

        $poolDriver = $this->poolDriver;
        if ($poolDriver == 'smf') {
            // Creates a Connection Pool (Channel) of Connections
            return $objConnectionPool->borrow();
        } else if ($poolDriver == 'swoole') {
            output('Getting Replaced Connection');
            // For swoole $objConnectionPool is of type SwoolePgConnectionPool, and ...
            // for OpenSwoole it is of type OpenSwoole\Core\Coroutine\Pool\ClientPool
            // For Swoole get() returns new Swoole\Coroutine\PostgreSQL();
            // For OpenSwoole get() returns new OpenSwoole\Coroutine\PostgreSQL();
            return $objConnectionPool->get(timeout: -1, replace: true);
        }
        else if($poolDriver=='openSwoole') {
            return $objConnectionPool->get();
        }
    }

    public function put_dbObject_using_pool_key($dbObj, $pool_key=null) {
        if (is_null($pool_key)) {
            $pool_key = $this->pool_key;
        }
        $objConnectionPool = $this->getConnectionPool($pool_key);
        $poolDriver = $this->poolDriver;
        try {
            if ($poolDriver == 'smf') {
                $objConnectionPool->return($dbObj);
            } else if ($poolDriver == 'swoole' || $poolDriver=='openSwoole') {
                $objConnectionPool->put($dbObj);
            }
        } catch (\Throwable $throwable) {
            throw $throwable;
        }
    }

    public function pool_exist($key)
    {
        return isset(static::$pools[$key]);
    }

    /**
     * Set the database connections pool size
     *
     * @param  mixed $poolSize The pool size you want to set
     * @return void
     */
    public function setPoolSize(int $poolSize) {
        $this->poolSize = $poolSize;
    }

    /**
     * Get the database connections pool size
     *
     * @return int The current pool size for DBConnectionPool object
     */
    public function getPoolSize():int {
        return $this->poolSize;
    }
}
