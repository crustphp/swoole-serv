<?php

namespace DB;

use Swoole\Coroutine\PostgreSQL;
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Smf\ConnectionPool\Connectors\PDOConnector;
use DB\ConnectionPoolTrait;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;

use OpenSwoole\Core\Coroutine\Client\PostgresClientFactory as oswPostgresClientFactory;
use OpenSwoole\Core\Coroutine\Client\PostgresConfig as oswPostgresConfig;
use OpenSwoole\Core\Coroutine\Pool\ClientPool as oswClientPool;

use DB\SwoolePgConfig;
use DB\SwoolePgConnectionPool;

include (dirname(dirname(__DIR__)).'/config/db_config.php');

class DBConnectionPool
{
    use ConnectionPoolTrait;

    private $pool_key;
    private string $dbEngine = 'postgres';
    private string $poolDriver = 'swoole';
    private $swoole_ext;

    function __construct($poolDriver='smf', string $dbEngine='postgres') {
        $this->poolDriver = strtolower($poolDriver);
        $this->dbEngine = $dbEngine;
        $this->swoole_ext = ($GLOBALS['swoole_ext'] ?? (extension_loaded('swoole') ? 1 : (
            extension_loaded('openswoole') ? 2 : 0)));
    }

    public function create($pool_key, bool $isPdo = true)
    {
        global $swPostgresServerHost;
        global $swPostgresServerPort;
        global $swPostgresServerDB;
        global $swPostgresServerUser;
        global $swPostgresServerPasswd;

        global $swMysqlServerDriver;
        global $swMysqlServerHost;
        global $swMysqlServerPort;
        global $swMysqlServerDb;
        global $swMysqlServerUser;
        global $swMysqlServerCharset;
        global $swMysqlServerPasswd;

        // For Smf package based Connection Pool

        $poolDriver = $this->poolDriver;
        // Configure Connection Pool through SMF ConnectionPool class constructor
        $obj_conn_pool = $this->create_connection_pool_object(
            $swPostgresServerHost,
            $swPostgresServerPort,
            $swPostgresServerDB,
            $swPostgresServerUser,
            $swPostgresServerPasswd,
            $isPdo,
            $poolDriver,
            $this->dbEngine
        );

        if ($poolDriver == 'smf') {
            // Creates a Connection Pool (Channel) of Connections
            $obj_conn_pool->init();
        } else if ($poolDriver == 'swoole') {
            $obj_conn_pool->fill();
        }

        // Key for Connection Pool through ConnectionPoolTrait
        $this->addConnectionPool($pool_key, $obj_conn_pool);
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
                    return new SwoolePgConnectionPool($config, $GLOBALS['db_connection_pool_size']);
                } else if ($this->swoole_ext = 2) {
                    $config = (
                    (new oswPostgresConfig())
                        ->withHost($serverHost)
                        ->withPort($serverPort)
                        ->withDbname($serverDB)
                        ->withUsername($serverUser)
                        ->withPassword($serverPasswd)
                    );
                    return new oswClientPool(oswPostgresClientFactory::class, $config, $GLOBALS['db_connection_pool_size']);
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

    public function create_pool($obj_conn_pool){
        // Creates a Connection Pool (Channel) of Connections
        $obj_conn_pool->init();
    }

    public function add_pool_with_key($obj_conn_pool, $poolKey = null) {
        $this->addConnectionPool($poolKey ?? $this->pool_key, $obj_conn_pool);
    }

    public function get_connection_pool_with_key($pool_key)
    {
        return $this->getConnectionPool($pool_key);
    }

    public static function get_connection($pool_key)
    {
        $connectionPool = static::getConnectionPool($pool_key);
        return $connectionPool->borrow();
    }

    public function pool_exist($key)
    {
        return isset(static::$pools[$key]);
    }
}
