<?php

return [
    'table_name' => 'ref_top_gainers',
    'table_size' => 1024, // Doubling the table size
    'columns' => [
        ['name' => 'calculated_value', 'type' => 'float'], // No size needed for floats
        ['name' => 'latest_value', 'type' => 'float'], // Same size for floats
        // ['name' => 'latest_update', 'type' => 'string', 'size' => 38], // Doubling the size for the timestamp
        ['name' => 'company_id', 'type' => 'int', 'size' => 8], // Same size for int

        // // Company-related fields with doubled size
        ['name' => 'en_long_name', 'type' => 'string', 'size' => 128], // Doubling the size
        ['name' => 'sp_comp_id', 'type' => 'string', 'size' => 32], // Doubling the size
        ['name' => 'en_short_name', 'type' => 'string', 'size' => 32], // Doubling the size
        ['name' => 'symbol', 'type' => 'string', 'size' => 20], // Doubling the size for 'SASE:9557'
        ['name' => 'isin_code', 'type' => 'string', 'size' => 28], // Doubling the size for ISIN code
        ['name' => 'created_at', 'type' => 'string', 'size' => 38], // Doubling the size for timestamp
        ['name' => 'ar_long_name', 'type' => 'string', 'size' => 128], // Doubling the size for long name
        ['name' => 'ar_short_name', 'type' => 'string', 'size' => 32], // Doubling the size for short name
        ['name' => 'ric', 'type' => 'string', 'size' => 20], // Doubling the size for RIC code
    ],
];
