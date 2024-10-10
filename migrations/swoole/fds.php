<?php
return [
    'table_name' => 'fds_table',
    'table_size' => 8192, // be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'columns' => [
        ['name' => 'fd', 'type' => 'int', 'size' => 8],
        ['name' => 'worker_id', 'type' => 'int', 'size' => 8],
    ],
];