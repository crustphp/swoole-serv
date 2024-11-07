<?php


namespace Bootstrap;

use \Crust\SwooleDb\Core\Column;
use \Crust\SwooleDb\Core\Enum\ColumnType;
use Crust\SwooleDb\Selector\TableSelector;
use \Crust\SwooleDb\Registry\TableRegistry;
use Crust\SwooleDb\Exception\TableNotExists;

class SwooleTableFactory
{
    const ALLOWED_TYPES = ['int', 'float', 'string'];
    static $migrationsExecuted = false;

    /**
     * The function creates a swoole table with specified name, rows, and column definitions
     *
     * @param string tableName The name of the table that you want to create
     * @param int rows The number of rows that the table should have. Default 1024
     * @param array columns_defs Contains definitions for the columns of the table being created
     *
     * @return mixed Returns the table that is created or false if it fails
     */
    public static function createTable(string $tableName, int $rows = 1024, array $columns_defs = [])
    {
        // For avoiding memory allocation error we will set the min rows to 1024
        $rows = $rows > 1024 ? $rows : 1024;

        try {
            $table = TableRegistry::getInstance()->createTable($tableName, $rows);

            if (empty($columns_defs)) {
                throw new \RuntimeException('Third argument $columns_defs can not be empty');
            } else {

                foreach ($columns_defs as $cDef) {
                    if (!is_array($cDef)) {
                        throw new \RuntimeException('Column definition should be an array.');
                    } else if (!array_key_exists('name', $cDef) || !array_key_exists('type', $cDef)) {
                        throw new \RuntimeException('"name" and "type" keys are required in column definition');
                    } else if (!in_array($cDef['type'], self::ALLOWED_TYPES)) {
                        throw new \RuntimeException('Invalid Column Type given. Acceptable values: ' . implode(',', self::ALLOWED_TYPES));
                    } else if ($cDef['type'] !== 'float' && !array_key_exists('size', $cDef)) {
                        throw new \RuntimeException('"size" key is required for non-float column type');
                    }

                    // Type Mapping
                    $mapping = [
                        'int' => ColumnType::int,
                        'float' => ColumnType::float,
                        'string' => ColumnType::string,
                    ];

                    if ($cDef['type'] == 'float') {
                        $table->addColumn(new Column($cDef['name'], $mapping[$cDef['type']]));
                    } else {
                        $table->addColumn(new Column($cDef['name'], $mapping[$cDef['type']], $cDef['size']));
                    }
                }
            }

            //  $success = TableRegistry::getInstance()->getTable($tableName)
            return $table->create();
        } catch (\RuntimeException | \Exception $e) {
            echo $e->getMessage();
            echo $e->getCode();
            echo $e->getFile();
            echo $e->getLine();

            self::destroyTable($tableName);

            throw $e;
        }
    }

    /**
     * Returns the table object by provided name
     *
     * @param  string $tableName
     * @return mixed
     */
    public static function getTable(string $tableName)
    {
        try {
            return TableRegistry::getInstance()->getTable($tableName);
        } catch (TableNotExists $e) {
            echo $e->getMessage();
            echo $e->getCode();
            echo $e->getFile();
            echo $e->getLine();

            return false;
        }
    }

    /**
     * getTableFactory
     *
     * @return mixed
     */
    public static function getTableFactory()
    {
        return TableRegistry::getInstance();
    }

    /**
     * The function updates the size of a table by creating a new table with the
     * specified size and transferring the data from the original table to the new one.
     *
     * @param mixed $table Instance/Object of Swoole Table
     * @param int $newSize The new size that you want to set for the table
     *
     * @return mixed
     */
    public static function updateTableSize(mixed $table, int $newSize)
    {
        if ($table->getMaxSize() > $newSize) {
            throw new \RuntimeException('Current table size is already greater than new size');
        }

        // We cannot create a new table with same name so first we have to take Backup of current Table data
        $tableName = $table->getName();
        $columnsDefinition = self::getColumnsStructure($table);

        $selector = new TableSelector($tableName);
        $records = $selector->execute();

        $columns = array_column($columnsDefinition, 'name');

        $data = [];
        foreach ($records as $record) {
            $d = [];
            foreach ($columns as $col) {
                $d[$col] = $record[$tableName]->getValue($col);
            }

            array_push($data, $d);
        }

        // Destroy the current Table
        self::destroyTable($tableName);

        // Create a new table with new size
        $newTable = self::createTable($tableName, $newSize, $columnsDefinition);

        // Store the data to new Table
        foreach ($data as $key => $d) {
            $newTable->set($key, $d);
        }

        return $newTable;
    }

    /**
     * This function will generate the array structure of columns from the Table Instance
     *
     * @param  mixed $table Instance/Object of Swoole Table
     * @return array
     */
    public static function getColumnsStructure(mixed $table)
    {
        $columnsStructure = [];
        $columns = $table->getColumns();
        foreach ($columns as $column) {
            $structure['name'] = $column->getName();
            $structure['type'] = $column->getType()->name;
            $structure['size'] = $column->getSize();
            if ($structure['type'] === 'float') {
                unset($structure['size']);
            }

            array_push($columnsStructure, $structure);
        }

        return $columnsStructure;
    }

    /**
     * This function will destroy/delete the the table
     *
     * @param  string $tableName Name of the table
     * @return void
     */
    public static function destroyTable(string $tableName)
    {
        TableRegistry::getInstance()->destroy($tableName);
    }

    /**
     * Check if the table exists
     *
     * @param  string $tableName
     * @return bool
     */
    public static function tableExists(string $tableName): bool
    {
        try {
            return (bool) TableRegistry::getInstance()->getTable($tableName);
        } catch (TableNotExists $e) {
            return false;
        }
    }

    /**
     * This function adds the data to the Swoole Table.
     * It also checks if the max rows size of Table is reached than it updates the table size
     *
     * @param  mixed $table Swoole Table
     * @param  mixed $key The key of the row
     * @param  array $data The array of data. It should be according to table Schema/Column Definition
     * @return mixed returns the table after adding the data
     */
    public static function addData($table, $key, array $data)
    {
        $added = $table->set($key, $data);

        // If the record is not added we will update the table size and re-call this function
        // We have added the second condition to check table count with table max size because single condition was not enough
        // With this second condition it will PHP Warning but we avoid Fetal Error when size is not enough

        // Swoole Small DB package returns null in case of failure instead of false (false return by Swoole/Table)
        if (is_null($added) || $table->count() == $table->getMaxSize()) {
            $newSize = $table->getMaxSize() * 2;
            $table = self::updateTableSize($table, $newSize);
            self::addData($table, $key, $data);
        }

        return $table;
    }

    /**
     * This function executes migrations for Swoole Tables
     *
     * @return void
     */
    public static function migrate(): void
    {
        // Migrations should be executed only once
        if (self::$migrationsExecuted) {
            echo 'Swoole Table migrations are already executed' . PHP_EOL;
            return;
        }

        $migrations = [];

        // Define the config directory path
        $migrationsPath = __DIR__ . '/../migrations/swoole/';

        // Load all migration files
        if (empty($config)) {
            foreach (glob($migrationsPath . '*.php') as $file) {
                $filename = basename($file, '.php');
                $migrations[$filename] = include $file;
            }
        }

        try {
            foreach ($migrations as $migrationFileName => $migration) {
                if (!array_key_exists('table_name', $migration) || !array_key_exists('table_size', $migration)) {
                    throw new \Exception("Swoole Table Migration: $migrationFileName is missing required 'table_name' or 'table_size' keys");
                }

                if (empty($migration['columns']) || !is_array($migration['columns'])) {
                    throw new \Exception("Swoole Table Migration: $migrationFileName must contain a valid 'columns' array");
                }

                if (self::tableExists($migration['table_name'])) {
                    throw new \Exception("Swoole Table Migration: $migrationFileName | Table " . $migration['name'] . " already exists");
                }

                self::createTable($migration['table_name'], $migration['table_size'], $migration['columns']);
            }

            self::$migrationsExecuted = true;
            echo 'Swoole Table migrations migrated successfully' . PHP_EOL;
        } catch (\Throwable $e) {
            echo 'Swoole Table Migrations Failed' . PHP_EOL;
            echo $e->getMessage() . PHP_EOL;
            echo $e->getCode() . PHP_EOL;
            echo $e->getFile() . PHP_EOL;
            echo $e->getLine() . PHP_EOL;
        }
    }

    /**
     * Get the data of the provided table
     *
     * @param  string $tableName Name of the table
     * @param  bool $withNulls Optional: If set to true it will also include columns having ::null
     * @param  mixed $encodeValues Optiona: Array of columns and target encoding if you want to encode the column values
     * @return mixed
     */
    public static function getTableData(string $tableName, bool $withMetaData = false, array $encodeValues = []): mixed
    {
        try {
            // Trim the $tableName to remove whitespaces
            $tableName = trim($tableName);

            // Validate if Table name is given
            if (empty($tableName)) {
                throw new \RuntimeException('Table name cannot be empty or null');
            }

            // Check if the encoding values are correct
            if (count($encodeValues)) {
                foreach ($encodeValues as $colName => $encoding) {
                    if (empty(trim($encoding))) {
                        throw new \RuntimeException('Encoding missing for column (' . $colName . ') for Table (' . $tableName . ')');
                    }
                }
            }


            // We fetch all the Records from the Table
            $tableSelector = new TableSelector($tableName);
            $tableRecords = $tableSelector->execute();

            // We get the columns names of table
            $smallDbTable = SwooleTableFactory::getTable($tableName);
            $tableColumns = SwooleTableFactory::getColumnsStructure($smallDbTable);
            $tableColumns = array_column($tableColumns, 'name');

            // Variable to store the final data to be returned
            $finalData = [];

            // Loop through table Records
            foreach ($tableRecords as $record) {
                // Fetch the data row collection from table and convert it to array
                $dataArray = $record[$tableName]->getData()->toArray();

                // Unset the extra columns containing ::null
                if (!$withMetaData) {
                    foreach ($tableColumns as $colName) {
                        unset($dataArray[$colName . '::null']);
                        unset($dataArray[$colName . '::sign']);
                    }
                }

                // Convert the encoding of Columns
                if (count($encodeValues)) {
                    foreach ($encodeValues as $colName => $encoding) {
                        if (isset($dataArray[$colName]) && !mb_check_encoding($dataArray[$colName], $encoding)) {
                            $dataArray[$colName] = mb_convert_encoding($dataArray[$colName], $encoding, 'auto');
                        }
                    }
                }

                $finalData[] = $dataArray;
            }

            return $finalData;
        } catch (\Throwable $e) {
            echo 'Failed to get data from Swoole Table: ' . $tableName . PHP_EOL;
            echo $e->getMessage() . PHP_EOL;
            echo $e->getCode() . PHP_EOL;
            echo $e->getFile() . PHP_EOL;
            echo $e->getLine() . PHP_EOL;
            throw $e;
        }
    }
}
