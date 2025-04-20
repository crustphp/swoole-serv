<?php

return [
    'table_name' => 'markets_overview',
    'table_size' => 10, // table size
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        ['name' => 'rising_companies', 'type' => 'int', 'size' => 8],
        ['name' => 'falling_companies', 'type' => 'int', 'size' => 8],
        ['name' => 'unchanged_companies', 'type' => 'int', 'size' => 8],
    ],
];
