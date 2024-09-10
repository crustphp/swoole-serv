<?php


namespace Bootstrap;

use \Small\SwooleDb\Core\Column;
use \Small\SwooleDb\Core\Enum\ColumnType;
use \Small\SwooleDb\Registry\TableRegistry;
use Small\SwooleDb\Exception\TableNotExists;


class SwooleTableFactory
{
    const ALLOWED_TYPES = ['int', 'float', 'string'];

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
            TableRegistry::getInstance()->destroy($tableName);
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
}
