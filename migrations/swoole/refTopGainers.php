<?php

return [
    'table_name' => 'ref_top_gainers',
    'table_size' => 1024, // table size
    'columns' => [
        ['name' => 'calculated_value', 'type' => 'float'], // float
        ['name' => 'latest_value', 'type' => 'float'], // float
        ['name' => 'latest_update', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp
        ['name' => 'company_id', 'type' => 'int', 'size' => 8], // size for int

        // // Company-related fields with doubled size
        ['name' => 'en_long_name', 'type' => 'string', 'size' => 128], // Doubling size
        ['name' => 'sp_comp_id', 'type' => 'string', 'size' => 32], // Doubling size
        ['name' => 'en_short_name', 'type' => 'string', 'size' => 32], // Doubling size
        ['name' => 'symbol', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'isin_code', 'type' => 'string', 'size' => 28], // Doubling the size
        ['name' => 'created_at', 'type' => 'string', 'size' => 38], // Doubling the size
        ['name' => 'ar_long_name', 'type' => 'string', 'size' => 128], // Doubling the size
        ['name' => 'ar_short_name', 'type' => 'string', 'size' => 32], // Doubling the size
        ['name' => 'ric', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'logo', 'type' => 'string', 'size' => 600], // String
        ['name' => 'market_id', 'type' => 'int', 'size' => 8], // int
        ['name' => 'market_name', 'type' => 'string', 'size' => 128], // String
    ],
];
