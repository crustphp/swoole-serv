<?php

namespace App\Core\Services;

use Smf\ConnectionPool\Connectors\PDOConnector;

class PdoService
{
    public $connectionConfig = [];
    protected $connection;
    protected $connector;

    /**
     * __construct
     *
     * @return void
     */
    public function __construct()
    {
        // Make a Database Connection
        $serverHost = config('db_config.sw_postgres_server_host');
        $serverPort = config('db_config.sw_postgres_server_port');
        $serverDB = config('db_config.sw_postgres_server_db');
        $serverUser = config('db_config.sw_postgres_server_user');
        $serverPasswd = config('db_config.sw_postgres_server_passwd');

        $this->connectionConfig = [
            'dsn' => 'pgsql:host=' . $serverHost . ';
                    port=' . $serverPort . ';
                    dbname=' . $serverDB,
            'username' => $serverUser,
            'password' => $serverPasswd,
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 30,
                \PDO::ATTR_PERSISTENT => true,
            ],
        ];

        $this->connector = new PDOConnector();
        $this->connection = $this->connector->connect($this->connectionConfig);

    }

    /**
     * Executes the query and return the results
     *
     * @param  string $query
     * @return mixed result
     */
    public function get(string $query): mixed
    {

        // Fetch the Registered Processes
        // Docs: https://www.php.net/manual/en/pdo.query.php
        $PDOStatement = $this->connection->query($query);
        if ($PDOStatement === false) {
            return false;
        }

        // Docs:https://www.php.net/manual/en/pdostatement.fetchall.php
        $result = $PDOStatement->fetchAll();


        return $result;
    }

    /**
     * Inserts the data into the given table
     *
     * @param string $table The table name
     * @param array $data An associative array of column names and values
     * @return mixed Returns the last insert ID on success or false on failure
     *
     * @throws \Throwable Exception
     */
    public function insert(string $table, array $data): mixed
    {
        try {

            // Construct the query like this example "INSERT INTO users (name, email) VALUES (:name, :email)"
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));

            $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

            $PDOStatement = $this->connection->prepare($sql);

            // Bind values
            foreach ($data as $key => $value) {
                if (is_bool($value)) {
                    // To prevent boolean value error (false bind as empty string "")
                    $PDOStatement->bindValue(":$key", $value, \PDO::PARAM_BOOL);
                } else {
                    $PDOStatement->bindValue(":$key", $value);
                }
            }

            // Execute the query
            if ($PDOStatement->execute()) {
                $lastInsertId = $this->connection->lastInsertId();
                return $lastInsertId;
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function update(string $table, array $data, string $condition, bool $applyTransaction = false, bool $applyLock = false): bool
    {

        try {
            // Start a transaction if required
            if ($applyTransaction) {
                $this->connection->beginTransaction();
            }

            // Conditionally apply ACCESS EXCLUSIVE MODE lock
            if ($applyLock) {
                if (!$applyTransaction) {
                    throw new \LogicException('Cannot apply a lock without a transaction. Enable $applyTransaction.');
                }
                $lockSql = "LOCK TABLE $table IN ACCESS EXCLUSIVE MODE";
                $this->connection->exec($lockSql);
            }

            // Construct the SET part of the query: "column1 = :column1, column2 = :column2"
            $setClause = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($data)));

            // Construct the SQL query
            $sql = "UPDATE $table SET $setClause WHERE $condition";
            $PDOStatement = $this->connection->prepare($sql);

            // Bind values
            foreach ($data as $key => $value) {
                $PDOStatement->bindValue(":$key", $value, is_bool($value) ? \PDO::PARAM_BOOL : \PDO::PARAM_STR);
            }

            // Execute the query
            $result = $PDOStatement->execute();

            // Commit the transaction if it was started
            if ($applyTransaction) {
                $this->connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            // Roll back the transaction on failure
            if ($applyTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $e; // Re-throw the exception for further handling
        }
    }

    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }

    public function lockTable($tableName)
    {
        $lockQuery = "LOCK TABLE " . $tableName . " IN ACCESS EXCLUSIVE MODE"; // Preventing all modifications
        $this->connection->exec($lockQuery);
    }

    /**
     * Generice function to form CRUD, can also be used to bulk operations
     *
     * @param  string $query
     * @param  array $params
     * @return mixed
     */
    public function query(string $query, array $params = []) : mixed
    {
        if (empty($params)) {
            $PDOStatement = $this->connection->query($query);
            return $PDOStatement->fetchAll();
        } else {
            $PDOStatement = $this->connection->prepare($query);
            if(isset($params[0]) && is_array($params[0])) {
                foreach ($params as $row) {
                    $PDOStatement->execute($row); // Executes with new values for each row
                }
                return true;
            } else {
                $PDOStatement->execute($params);
                return $PDOStatement->fetchAll();
            }
        }
    }

    public function commitTransaction()
    {
        $this->connection->commit();
    }

    public function rollBackTransaction()
    {
        $this->connection->rollBack();
    }

    public function close()
    {
        // Disconnect the connection
        $this->connector->disconnect($this->connection);
    }

    public function __destruct()
    {
        $this->close();
    }

}
