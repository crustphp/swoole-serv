<?php
// This table should have only one row for consitency and avoiding un-expected results
return [
    'table_name' => 'worker_stop_code',
    'table_size' => 64, // be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'columns' => [
        ['name' => 'is_shutting_down', 'type' => 'int', 'size' => 1],
    ],
];
