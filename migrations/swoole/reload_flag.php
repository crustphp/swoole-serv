<?php
// This table should have only one row for consitency and avoiding un-expected results
return [
    'table_name' => 'reload_flag',
    'table_size' => 64, // be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'columns' => [
        // reload flag: saves the status of server->reload() [i.e reload-code]
        ['name' => 'reload_flag', 'type' => 'int', 'size' => 4],
    ],
];