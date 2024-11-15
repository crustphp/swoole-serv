<?php

namespace App\Core\Services;

use Smf\ConnectionPool\Connectors\PDOConnector;

class PdoService
{
    public $connectionConfig = [];

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
            ],
        ];
    }

    /**
     * Executes the query and return the results
     *
     * @param  string $query
     * @return mixed result
     */
    public function query(string $query): mixed
    {
        $connector = new PDOConnector();
        $connection = $connector->connect($this->connectionConfig);

        // Fetch the Registered Processes
        // Docs: https://www.php.net/manual/en/pdo.query.php
        $PDOStatement = $connection->query($query);
        if ($PDOStatement === false) {
            return false;
        }

        // Docs:https://www.php.net/manual/en/pdostatement.fetchall.php
        $result = $PDOStatement->fetchAll();

        // Disconnect the connection
        $connector->disconnect($connection);

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
            $connector = new PDOConnector();
            $connection = $connector->connect($this->connectionConfig);

            // Construct the query like this example "INSERT INTO users (name, email) VALUES (:name, :email)"
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));

            $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";

            $PDOStatement = $connection->prepare($sql);

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
                $lastInsertId = $connection->lastInsertId();
                $connector->disconnect($connection);
                return $lastInsertId;
            } else {
                $connector->disconnect($connection);
                return false;
            }
        } catch (\Throwable $e) {
            $connector->disconnect($connection);
            throw $e;
        }
    }
}
