<?php
// This migration stores the FDs that have subscribed to particular topic/module
return [
    'table_name' => 'topic_subscribers',
    'table_size' => 8192, // (be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'columns' => [
        ['name' => 'topic', 'type' => 'string', 'size' => 64],
        ['name' => 'fd', 'type' => 'int', 'size' => 8],
        ['name' => 'worker_id', 'type' => 'int', 'size' => 8],
    ],
];
