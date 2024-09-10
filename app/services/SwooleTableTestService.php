<?php

use Bootstrap\SwooleTableFactory;

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
        $columns = [
            ['name' => 'email', 'type' => 'string', 'size' => 100],
            ['name' => 'rollno', 'type' => 'int', 'size' => 10],
            ['name' => 'height', 'type' => 'float'],
        ];

        // Create table (TableName, TotalRows, ColumnDefinitions)
        $this->swooleTableFactory = SwooleTableFactory::createTable('test_table', 1024, $columns);
    }

    public function handle()
    {
        // Following statement works but for elaboration purpose i am using getTable static method
        // $table = $this->swooleTableFactory;

        $table = SwooleTableFactory::getTable('test_table');
        if ($table === false) {
            dump('table not found');
            return;
        }

        // Set the Data
        $table->set(0, ['email' => 'mohsin@gmail.com', 'rollno' => 16, 'height' => 5.8]);
        $table->set(1, ['email' => 'ali@gmail.com', 'rollno' => 12, 'height' => 5.3]);

        // We can also set associative key e.g below
        // $table->set('student_one', ['email' => 'mohsin@gmail.com', 'rollno' => 12, 'height' => 6.2]);

        go(function () use ($table) {
            var_dump($table->get(0));
            var_dump($table->get(1, 'rollno'));

            // To get data of associated key row. e.g below
            // var_dump($table->get('key_one', 'email'));

            echo PHP_EOL;
            echo 'Ended';
        });
    }
}
