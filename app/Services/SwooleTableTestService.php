<?php

namespace App\Services;

use Bootstrap\SwooleTableFactory;
use Crust\SwooleDb\Selector\TableSelector;
use Crust\SwooleDb\Selector\Enum\ConditionElementType;
use Crust\SwooleDb\Selector\Enum\ConditionOperator;
use Crust\SwooleDb\Selector\Bean\ConditionElement;
use Crust\SwooleDb\Selector\Bean\Condition;

class SwooleTableTestService
{
    protected $webSocketServer;
    protected $frame;

    protected $swooleTableFactory;

    public function __construct($webSocketServer, $frame)
    {
        $this->webSocketServer = $webSocketServer;
        $this->frame = $frame;

        // Create the Swoole Table
        // Types can be (string, int, float)
        // Size is required in case type is string or int 
        // $columns = [
        //     ['name' => 'email', 'type' => 'string', 'size' => 100],
        //     ['name' => 'rollno', 'type' => 'int', 'size' => 10],
        //     ['name' => 'height', 'type' => 'float'],
        // ];

        // // Create table (TableName, TotalRows, ColumnDefinitions)
        // if (!SwooleTableFactory::tableExists('test_table')) {
        //     $this->swooleTableFactory = SwooleTableFactory::createTable('test_table', 32, $columns);
        // }
    }

    public function newHandle()
    {
        $crustTable = SwooleTableFactory::getTable('users');
        $swooleTable = $crustTable->getSwooleTable();

        $user3 = [
            'id' => 3,
            'name' => null,
            'email' => 'michael.johnson@example.com',
            'height' => 6.1,
            'age' => null,
        ];

        // Add New Users
        if ($crustTable->count() == 0) {
            $users = $this->getDummyUsers();
            $counter = 1;
            foreach ($users as $user) {
                if ($user['id'] == 3) {
                    $user = [
                        'id' => 3,
                        'name' => null,
                        'email' => 'michael.johnson@example.com',
                        'height' => 6.1,
                        'age' => null,
                    ];
                }

                $crustTable->set($user['id'], $user);
                
                if ($counter == 3) {
                    break;
                }

                $counter++;
            }
        }

        // $data = SwooleTableFactory::getSwooleTableData('users', ['name', 'email'], null, true);
        // output($data);
        // $data = $crustTable->getSwooleTableData();
        // output(data: $data, shouldVarDump: true);

        // --- Test Case #1 >> Check if Table contains meta columns
        // output(SwooleTableFactory::getColumnsStructure($crustTable));
        output($crustTable->getSwooleTable());

        // $userCrust = $crustTable->get(3);
        // $swooleTable->set(3, $user3);
        $userSwoole = $swooleTable->get(3);

        // output($userCrust);
        output($userSwoole);

        // output('------------------');
        // $data = SwooleTableFactory::getDataFromSwooleTable('users');

        // output($data);
        
        // $this->push($userCrust->toArray());
        
        // --- Test Case #2 >> Can we loop through Crust Table
        // foreach($swooleTable as $row) {
        //     output($row);
        // }

        // foreach($crustTable as $row) {
        //     output($row->getData());
        // }

        $this->push('Operation Completed');
    }

    public function handle()
    {
        // Following statement works but for elaboration purpose i am using getTable static method
        // $table = $this->swooleTableFactory;

        $table = SwooleTableFactory::getTable('test_table');
        if ($table === false) {
            echo 'table not found' . PHP_EOL;
            return;
        }

        // You can set the data in the table using the following code Example 1 and Example 2
        // in set() first parameter is the key of the data. It could be a string or integer. We can fetch the data using this key.
        // the second parameter is an array of values we want to store (According to defined Table Schema/Column Definition)
        // Example 1
        $table->set(0, ['email' => 'mohsin@gmail.com', 'rollno' => 16, 'height' => 5.8]);
        $table->set(1, ['email' => 'ali@gmail.com', 'rollno' => 12, 'height' => 5.3]);

        // Example 2: The following code I am using a string key.
        $table->set('student_one', ['email' => 'ali@gmail.com', 'rollno' => 12, 'height' => 5.3]);

        // We can delete the table Data using del() passing the key as parameter
        // You can use the $table->count() code to check number of rows in table
        $table->del(0);
        $table->del(1);
        $table->del('student_one');


        // In Swoole Table we have a limit on number of rows
        // So in-case we have more data rows, we can use the Update Table Size function to set the new size/length
        // In following example we have a table with 32 rows, then we will increase the size to 100
        // $table = SwooleTableFactory::updateTableSize($table, 1024);

        // Now we can store more than 32 rows into the table
        $size = 100;
        for ($i = 0; $i < $size; $i++) {
            $key = $i;
            $table = SwooleTableFactory::addData($table, $key, ['email' => 'mohsin.' . $i . '@gmail.com', 'rollno' => $i + 1, 'height' => 5.8]);
            // $table->set($i, ['email' => 'mohsin.' . $i . '@gmail.com', 'rollno' => 16 + $i, 'height' => 5.8]);
        }

        // We can check the size of the table using $table->getMaxSize()
        echo $table->count() . PHP_EOL;
        echo $table->getMaxSize() . PHP_EOL;

        go(function () use ($table, $size) {
            // We can get the record using get() passing the key of record/row
            // Following code will return us all the columns of row 0
            var_dump($table->get(0));

            // To get specific column/field we can pass that column name as second parameter in get()
            var_dump($table->get(1, 'rollno'));

            // To get data of associated key row. e.g below
            // var_dump($table->get('key_one', 'email'));

            echo '---------------------------' . PHP_EOL;
            echo 'For dynamic size update, we verify if the table has store all of our data' . PHP_EOL;
            echo '---------------------------' . PHP_EOL;
            for ($i = 0; $i < $size; $i++) {
                echo $table->get($i, 'rollno') . PHP_EOL;
            }
        });
    }

    public function getDummyUsers()
    {
        $users = [
            [
                'id' => 1,
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'height' => 5.9,
                'age' => 17,
            ],
            [
                'id' => 2,
                'name' => 'Jane Smith',
                'email' => 'jane.smith@example.com',
                'height' => 5.4,
                'age' => 24,
            ],
            [
                'id' => 3,
                'name' => 'Michael Johnson',
                'email' => 'michael.johnson@example.com',
                'height' => 6.1,
                'age' => 31,
            ],
            [
                'id' => 4,
                'name' => 'Emily Davis',
                'email' => 'emily.davis@example.com',
                'height' => 5.7,
                'age' => 9,
            ],
            [
                'id' => 5,
                'name' => 'Chris Brown',
                'email' => 'chris.brown@example.com',
                'height' => 6.0,
                'age' => 31,
            ],
            [
                'id' => 6,
                'name' => 'Jessica White',
                'email' => 'jessica.white@example.com',
                'height' => 5.6,
                'age' => 29,
            ],
            [
                'id' => 7,
                'name' => 'David Wilson',
                'email' => 'david.wilson@example.com',
                'height' => 5.8,
                'age' => 60,
            ],
            [
                'id' => 8,
                'name' => 'Sarah Miller',
                'email' => 'sarah.miller@example.com',
                'height' => 5.4,
                'age' => 24,
            ],
            [
                'id' => 9,
                'name' => 'James Anderson',
                'email' => 'james.anderson@example.com',
                'height' => 5.9,
                'age' => 40,
            ],
            [
                'id' => 10,
                'name' => 'Laura Martinez',
                'email' => 'laura.martinez@example.com',
                'height' => 5.5,
                'age' => 19,
            ],
        ];

        return $users;
    }

    public function push(mixed $data) {
        if ($this->webSocketServer->isEstablished($this->frame->fd)) {
            $dataToPush = is_array($data) ? json_encode($data) : $data;
            $this->webSocketServer->push($this->frame->fd, $dataToPush);
        }
    }
}
