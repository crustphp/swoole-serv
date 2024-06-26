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

class DBFacade {
    public function query($db_query, $conn_pool, array $options=null, $transaction=false)
    {

        ////////////////////////////////////////////////////////////////////////////////
        //// Get DB Connection from a Connection Pool created through 'smf' package ////
        ////////////////////////////////////////////////////////////////////////////////

        /**@var POSTGRES $postgres */
        $db_connection = $conn_pool->borrow();

        if ($transaction){
            $db_connection->query('BEGIN');
        }
        if ($db_connection instanceof \PDO) {
            $pdo_statement = $db_connection->prepare($db_query);

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
            $pdo_statement = $db_connection->query($db_query);
            if (!$pdo_statement) {
//                if ($ret === false) {
//                    throw new \RuntimeException(sprintf('Failed to connect PostgreSQL server: %s', $connection->error));
//                }
                throw new \RuntimeException('pdo function query() failed');
            }
        }
        $data = $pdo_statement->fetchAll();

        if ($transaction){
            $db_connection->query('COMMIT');
        }

        // Return the connection to pool as soon as possible
        $conn_pool->return($db_connection);

        return $data;
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
