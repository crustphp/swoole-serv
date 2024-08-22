<?php


namespace Bootstrap;

use \Small\SwooleDb\Registry\TableRegistry;
use \Small\SwooleDb\Core\Column;
use \Small\SwooleDb\Core\Enum\ColumnType;


class SwooleTableFactory
{

    public function __construct($tableName, $rows=1024, array $columns_defs=[]) {
        try {
            $table = TableRegistry::getInstance()->createTable($tableName, $rows);

            if (empty($columns_defs)) {
                throw new \RuntimeException('Third argument $columns_defs can not be empty');
            } else {
                //  TableRegistry::getInstance()->getTable($tableName)
                foreach ($columns_defs as $column_defs) {
                    if (!is_array($column_defs)) {
                        TableRegistry::getInstance()->destroy($tableName);
                        throw new \RuntimeException('Column definition should be an array.');
                    } else if (count($column_defs) < 3) {
                        if (count($column_defs) < 2) {
                            TableRegistry::getInstance()->destroy($tableName);
                            throw new \RuntimeException('Column definition must have \'column name\' and \'column type\' information.');
                        } else if ($column_defs['type'] != 'float') {
                            TableRegistry::getInstance()->destroy($tableName);
                            throw new \RuntimeException('For non-float type of columns, size of column is required.');
                        }
                    } else if (count($column_defs) > 4 ) {
                        TableRegistry::getInstance()->destroy($tableName);
                        throw new \RuntimeException('Column Definition is exceeding the max. limit of 3');
                    }

                    $table = $table->addColumn(new Column($column_defs['name'], ColumnType::$column_defs['type'], 256));
                }
            }


            //  $success = TableRegistry::getInstance()->getTable($tableName)
            if ($table->create()) {
                return $table;
            } else {

            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            echo $e->getCode();
            echo $e->getFile();
            echo $e->getLine();
        }
    }

    public function getTable($tableName) {
       return TableRegistry::getInstance()->getTable($tableName);
    }

    public function getTableFactory() {
        return TableRegistry::getInstance();
    }

}
