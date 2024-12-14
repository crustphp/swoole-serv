<?php
declare(strict_types=1);

// For PostgreSQL Connection Pool
//use Smf\ConnectionPool\ConnectionPool;
//use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
//use Smf\ConnectionPool\Connectors\PDOConnector;

//use Swoole\Database\PDOConfig;
//use Swoole\Database\PDOPool;
namespace DB;
use Swoole\Runtime;
use DB\SwoolePgConnectionPool;

// use Swoole\Coroutine as Co;

class DbFacade {

    public function query($db_query, $objDbPool, array $options = null, $transaction = false, $lock = false, $tableName = '')
    {
        ////////////////////////////////////////////////////////////////////////////////
        //// Get DB Connection from a Connection Pool created through 'smf' package ////
        ////////////////////////////////////////////////////////////////////////////////

        // Test Later: If there is not connection available then wait for 200 milliseconds and try again
        // while (empty($postgresClient) || is_null($postgresClient)) {
        //     Co::sleep(0.2);

        //     $postgresClient = $objDbPool->get_dbObject_using_pool_key();
        // }

        $connectionPoolObj = $objDbPool->get_connection_pool_with_key();
        $postgresClient = $objDbPool->get_dbObject_using_pool_key();
        $connectionTested = false;

        if ($transaction) {
            if (!$pdo_statement = $postgresClient->query('BEGIN')) {
                $postgresClient = $this->getActivePostgresClient($postgresClient, $connectionPoolObj, $objDbPool);
                $pdo_statement = $postgresClient->query('BEGIN');
                $connectionTested = true;
                
                if(!$pdo_statement) {
                    throw new \RuntimeException('pdo function query() failed: ' . (isset($postgresClient->errCode) ? $postgresClient->errCode : ''));
                }
            }
        }

        // Apply table-level lock conditionally
        if ($lock && !empty($tableName)) {
            $lockQuery = "LOCK TABLE " . $tableName . " IN ACCESS EXCLUSIVE MODE";
            $pdo_statement = $postgresClient->query($lockQuery);

            if (!$connectionTested && !$pdo_statement) {
                $postgresClient = $this->getActivePostgresClient($postgresClient, $connectionPoolObj, $objDbPool);
                $pdo_statement = $postgresClient->query($lockQuery);
                $connectionTested = true;

                if(!$pdo_statement) {
                    if ($transaction) {
                        $postgresClient->query('ROLLBACK');
                    }

                    throw new \RuntimeException('pdo function query() failed: ' . (isset($postgresClient->errCode) ? $postgresClient->errCode : ''));
                }
            }
        }

        if ($postgresClient instanceof \PDO) {
            $pdo_statement = $postgresClient->prepare($db_query);

            if (!$pdo_statement) {
                //                try {
                //                    $connection = new \PDO($config['dsn'], $config['username'] ?? '', $config['password'] ?? '', $config['options'] ?? []);
                //                } catch (\Throwable $e) {
                //                    throw new \RuntimeException(sprintf('Failed to connect the requested database: [%d] %s', $e->getCode(), $e->getMessage()));
                //                }
                throw new \RuntimeException('Prepare failed');
            }

            $result = $pdo_statement->execute($options);

            if (!$result) {
                throw new \RuntimeException('Execute failed');
            }
        } else { // For Non-pdo postgresql connection use below:
            if (!$connectionTested && !$pdo_statement = $postgresClient->query($db_query)) {
                $postgresClient = $this->getActivePostgresClient($postgresClient, $connectionPoolObj, $objDbPool);
                $pdo_statement = $postgresClient->query($db_query);
                // $connectionTested = true; // Not Required Here
                
                if(!$pdo_statement) {
                    if ($transaction) {
                        $postgresClient->query('ROLLBACK');
                    }

                    throw new \RuntimeException('pdo function query() failed: ' . (isset($postgresClient->errCode) ? $postgresClient->errCode : ''));
                }
            }
        }

        $data = $pdo_statement->fetchAll();

        if ($transaction) {
            $postgresClient->query('COMMIT');
        }

        // Return the connection to pool as soon as possible
        $objDbPool->put_dbObject_using_pool_key($postgresClient);

        return $data;        
    }
    
    /**
     * This function replaces disconnected Postgres Client with a fresh one.
     *
     * @param  mixed $postgresClient
     * @param  mixed $connectionPoolObj
     * @param  mixed $objDbPool
     * @return mixed
     */
    public function getActivePostgresClient($postgresClient, $connectionPoolObj, $objDbPool): mixed {
        if (!$connectionPoolObj->isClientConnected($postgresClient)) {
            return $objDbPool->get_replaced_dbObject();
        }

        return $postgresClient;
    }

    public function getClient($objDbPool) {
        $postgresClient = $objDbPool->get_dbObject_using_pool_key();

        // Test Later: If there is not connection available then wait for 200 milliseconds and try again
        // while (empty($postgresClient) || is_null($postgresClient)) {
        //     Co::sleep(0.2);

        //     $postgresClient = $objDbPool->get_dbObject_using_pool_key();
        // }

        return $postgresClient;
    }

    public function beginTransaction($postgresClient) {
        $postgresClient->query('BEGIN');
    }

    public function lockTable( $postgresClient, $tableName) {
        $lockQuery = "LOCK TABLE" . $tableName . " IN ACCESS EXCLUSIVE MODE"; // Preventing all modifications
        $postgresClient->query($lockQuery);
    }

    public function commitTransaction($postgresClient)
    {
        $postgresClient->query('COMMIT');
    }

    public function rollBackTransaction ($postgresClient) {
        $postgresClient->query('ROLLBACK');
    }
}



// PDO Example for PostgreSQL
// PDO.php on https://github.com/openswoole/ext-postgresql/tree/master/examples
// Also see example of prepared statement in Prepared.php
// Reachable from https://github.com/orgs/openswoole/repositories

//        $dbh = new PDO('pgsql:dbname=test;
//                           host=127.0.0.1;
//                           user=postgres;
//                           password=postgres');
//        $res = $dbh->query('SELECT * FROM weather');
//        var_dump($res->fetchAll());

///////////////////////////////////////////////////////
////////////////////// REDIS //////////////////////////
/// ///////////////////////////////////////////////////

//            // All Redis connections: [4 workers * 5 = 20, 4 workers * 20 = 80]
//            return new ConnectionPool(
//                [
//                    'minActive' => 5,
//                    'maxActive' => 20,
//                ],
//                new PhpRedisConnector,
//                [
//                    'host'     => '127.0.0.1',
//                    'port'     => '6379',
//                    'database' => 0,
//                    'password' => null,
//                ]);

/////////////////////////////////
// Other Example of PostgreSQL //
/////////////////////////////////


// https://github.com/deminy/swoole-by-examples/blob/master/examples/clients/postgresql.php
