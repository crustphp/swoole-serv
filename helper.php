<?php

if (!function_exists('hdump')){
    function hdump($var)
    {
        return highlight_string("<?php\n\$array = " . var_export($var, true) . ";", true);
    }
}

// Display data to terminal or logs (in demonize mode)
if (!function_exists('output')) {
    /**
     * Display data to terminal or logs (in daemon mode)
     * You can also pass the instance of Exception / Throwable to log the exception
     *
     * @param mixed $data  The data to be printed or logged.
     * @param mixed $server  Optional The Swoole server instance 
     * @param bool $shouldExit  If true, exit the script after output.
     * @param bool $shouldVarDump  If true, use var_dump
     * 
     * @return void
     */
    function output(mixed $data, mixed $server = null, bool $shouldExit = false, bool $shouldVarDump = false): void
    {
        // New line at start and begining of output to make it appear seperated from the rest
        echo PHP_EOL;

        if ($shouldVarDump) {
            var_dump($data);
        } else {
            // If the Data is an Exception (Instance of Throwable)
            if ($data instanceof \Throwable) {
                echo 'Exception Occured: ' . PHP_EOL;
                echo 'Message: ' . $data->getMessage() . PHP_EOL;
                echo 'In File: ' . $data->getFile() . PHP_EOL;
                echo 'On Line: ' . $data->getLine() . PHP_EOL;
                echo 'Having Code: ' . $data->getCode() . PHP_EOL;
                echo 'Stack Trace:' . PHP_EOL;
                echo $data->getTraceAsString() . PHP_EOL;
            }
            // Print_r the Data for Arrays or Objects
            else if (is_array($data) || is_object($data)) {
                print_r($data);
            }
            // Simply echo the scalar values
            else {
                echo $data;
            }
        }

        echo PHP_EOL;

        // Shutdown and exit (Conditional)
        if ($shouldExit) {
            if (is_null($server)) {
                // Case: For coroutine\run (when swoole is not running a Server), but not tested
                try {
                    \Swoole\Coroutine::sleep(.001);
                    exit(911);
                } catch (\Swoole\ExitException $e) {
                    // var_dump($e->getMessage());
                    // var_dump($e->getStatus() === 1);
                    // var_dump($e->getFlags() === SWOOLE_EXIT_IN_COROUTINE);

                    // If its not coroutine then force shutdown
                    forceShutdown();

                    sleep(3);
                    exit(1);
                }
            } else {
                // Tested: When Swoole is running a server
                $shutdownRes = $server->shutdown();
                if (!$shutdownRes) {
                    forceShutdown();
                }

                sleep(3);
                exit(1);
            }
        }
    }
}

// Force Shutdown the Server
if (!function_exists('forceShutdown')) {
    /**
     * Force Shutdown the Server
     *
     * @param int $port  The port of the server
     * 
     * @return void
     */
    function forceShutdown(int $port = 9501): void
    {
        // Force shutdown by server.pid or by killing processes listening on server port
        if (file_exists('server.pid')) {
            exec('cd ' . __DIR__ . ' && kill -9 `cat server.pid` 2>&1 1> /dev/null && rm -f server.pid');
        } else {
            exec('cd ' . __DIR__ . ' && kill -SIGKILL $(lsof -t -i:' . $port . ') 2>&1 1> /dev/null');
        }
    }
}

function makePoolKey($id, $dbEngine) {
    $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
    $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
    $dbEngine = strtolower($dbEngine);
    if ($dbEngine == 'postgres') {
        return $swoole_pg_db_key.$id;
    } else if ($dbEngine == 'mysql') {
        return $swoole_mysql_db_key.$id;
    } else {
        throw new \RuntimeException('Inside helper.php->function makePoolKey(), Argument $dbEngine should either be \'postgres\' or \'mysql\'.');
    }
}

// Get the ID (Worker ID) from the DB Connection Pool Key
if (!function_exists('getIdFromDbPoolKey')) {
    /**
     * Get the ID (Worker ID) from the DB Connection Pool Key
     *
     * @param  string $poolKey The database connection pool key.
     * @param  string $dbEngine The database engine ('postgres' or 'mysql').
     * 
     * @return int The extracted ID as an integer.
     * 
     * @throws \RuntimeException If the $poolKey or $dbEngine is invalid.
     */
    function getIdFromDbPoolKey($poolKey, $dbEngine): int
    {
        $swoole_pg_db_key = config('app_config.swoole_pg_db_key');
        $swoole_mysql_db_key = config('app_config.swoole_mysql_db_key');
        $dbEngine = strtolower($dbEngine);

        if ($dbEngine == 'postgres') {
            if (strpos($poolKey, $swoole_pg_db_key) === 0) {
                return (int) substr($poolKey, strlen($swoole_pg_db_key));
            }
        } elseif ($dbEngine == 'mysql') {
            if (strpos($poolKey, $swoole_mysql_db_key) === 0) {
                return (int) substr($poolKey, strlen($swoole_mysql_db_key));
            }
        } else {
            throw new \RuntimeException('Inside helper.php->function extractIdFromPoolKey(), Argument $dbEngine should either be \'postgres\' or \'mysql\'.');
        }

        throw new \RuntimeException("Invalid Pool Key ($poolKey) or mismatch with the provided DB Engine ($dbEngine).");
    }
}

// Config Helper Function
if (!function_exists('config')) {
    /**
     * Get a configuration value from the config files.
     *
     * @param  string  $key  The configuration key in "dot" notation (e.g., 'app.timezone').
     * @param  mixed   $default  The default value to return if the key doesn't exist.
     * @return mixed   The configuration value or the default value if the key is not found.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = [];

        // Define the config directory path
        $configPath = __DIR__ . '/config/';

        // Load all configuration files once
        if (empty($config)) {
            foreach (glob($configPath . '*.php') as $file) {
                $filename = basename($file, '.php');
                $config[$filename] = include $file;
            }
        }

        // Parse the key, if key exists than return the value otherwise return default
        $keys = explode('.', $key);
        $result = $config;

        foreach ($keys as $segment) {
            if (isset($result[$segment])) {
                $result = $result[$segment];
            } else {
                return $default;
            }
        }

        return $result;
    }
}

// Get all the directories and their sub-directories recurrsivly
if (!function_exists('getAllDirectories')) {
    /**
     * Get all the directories and their sub-directories recurrsivly
     *
     * @param string $dir The base directory to start scanning from.
     * @param array $skipDirs An array of directory names to skip during scanning.
     * 
     * @return mixed A list of final directories
     */
    function getAllDirectories($dir, $skipDirs = []): mixed
    {
        // Get first-level directories
        $dirs = glob($dir . '/*', GLOB_ONLYDIR);
        $allDirs = [];

        foreach ($dirs as $d) {

            // Skip directories that are in the $skipDirs array
            // Basename() example /var/www/html/muasherat/swoole-serv/app/Core/Enum  -> will return "Enum"
            if (in_array(basename($d), $skipDirs)) {
                continue;
            }

            // Add the current directory
            $allDirs[] = $d;

            // Recursively get subdirectories
            $subDirs = getAllDirectories($d, $skipDirs);

            // Merge subdirectories into the final list
            $allDirs = array_merge($allDirs, $subDirs);
        }

        return $allDirs;
    }
}

// Reads and decodes JSON data from a file.
if (!function_exists('readJsonFile')) {
    /**
     * Reads and decodes JSON data from a file.
     *
     * @param string $absoluteFilePath The absolute path to the JSON file.
     * @return array The decoded data as a PHP array, or an empty array if the file does not exist or has no valid data.
     */
    function readJsonFile(string $absoluteFilePath): array
    {
        // Check if the file exists and read its contents
        if (file_exists($absoluteFilePath)) {
            $fileContents = file_get_contents($absoluteFilePath);
            $decodedData = json_decode($fileContents, true);

            // Return the decoded data if it is an array (Check added as it returns null if file is empty), otherwise return an empty array
            return is_array($decodedData) ? $decodedData : [];
        }

        // Return an empty array if the file does not exist
        return [];
    }
}

// Writes data to a JSON file using a specified mode. If the file does not exist, it will be created.
if (!function_exists('writeDataToJsonFile')) {
    /**
     * Writes data to a JSON file using a specified mode. If the file does not exist, it will be created.
     * Supported modes:
     * - 'a': Append mode. Appends the new data to the existing content.
     * - 'w': Write mode. Overwrites the existing content with the new data.
     *
     * @param array $data The data array to write to the JSON file.
     * @param string $absoluteFilePath The absolute path to the JSON file.
     * @param string $mode The mode of writing: 'a' (append) or 'w' (overwrite). By default it will append the data
     * 
     * @return bool Returns true if the data has been written successfully, false otherwise.
     */
    function writeDataToJsonFile(array $data, string $absoluteFilePath, string $mode = 'a'): bool
    {
        $existingData = [];

        if ($mode === 'a') {
            // Append mode: Read existing data and add the new data
            $existingData = readJsonFile($absoluteFilePath);
            $existingData[] = $data;
        } elseif ($mode === 'w') {
            // Write mode: Overwrite with new data
            $existingData = $data;
        } else {
            // Invalid mode, return false
            return false;
        }

        // Write the updated data back to the file
        try {
            // Json Flags: https://www.php.net/manual/en/function.json-encode.php
            // LOCK_EX: https://www.php.net/manual/en/function.file-put-contents.php
            $result = file_put_contents($absoluteFilePath, json_encode($existingData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        } catch (\Throwable $e) {
            output($e);
            return false;
        }

        // Return true if data was written successfully, otherwise false
        return $result !== false;
    }
}

if (!function_exists('removeItemFromJsonFile')) {
    /**
     * Removes an entry/record from a JSON file that matches the specified key-value pair.
     *
     * @param string $key The key to match for removing the entry.
     * @param mixed $value The value to match for removing the entry.
     * @param string $absoluteFilePath The absolute path to the JSON file.
     * 
     * @return bool Returns True if the entry was removed, False otherwise.
     */
    function removeItemFromJsonFile(string $key, $value, string $absoluteFilePath): bool
    {
        // Read the existing data from the file
        $data = readJsonFile($absoluteFilePath);

        // Return false if there is no data
        if (empty($data)) {
            return false;
        }

        // Iterate over the data and remove Item that match the key-value pair
        $filteredData = [];
        foreach ($data as $item) {
            if (!isset($item[$key]) || $item[$key] != $value) {
                $filteredData[] = $item;
            }
        }

        // Write the updated data back to the file
        return writeDataToJsonFile($filteredData, $absoluteFilePath, 'w');
    }
}
