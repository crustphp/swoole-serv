<?php

return [
    'table_name' => 'sectors_indicators',
    'table_size' => 10, // table size
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        // Refinitiv Indicators
        ['name' => 'cf_high', 'type' => 'float'], // float
        ['name' => 'cf_last', 'type' => 'float'], // float
        ['name' => 'cf_low', 'type' => 'float'], // float
        ['name' => 'cf_volume', 'type' => 'float'], // float
        ['name' => 'high_1', 'type' => 'float'], // float
        ['name' => 'hst_close', 'type' => 'float'], // float
        ['name' => 'low_1', 'type' => 'float'], // float
        ['name' => 'netchng_1', 'type' => 'float'], // float
        ['name' => 'num_moves', 'type' => 'float'], // float
        ['name' => 'open_prc', 'type' => 'float'], // float
        ['name' => 'pctchng', 'type' => 'float'], // float
        ['name' => 'trdprc_1', 'type' => 'float'], // float
        ['name' => 'turnover', 'type' => 'float'], // float
        ['name' => 'yrhigh', 'type' => 'float'], // float
        ['name' => 'yrlow', 'type' => 'float'], // float
        ['name' => 'yr_pctch', 'type' => 'float'], // float
        ['name' => 'cf_close', 'type' => 'float'], // float
        ['name' => 'bid', 'type' => 'float'], // float
        ['name' => 'ask', 'type' => 'float'], // float
        ['name' => 'asksize', 'type' => 'float'], // float
        ['name' => 'bidsize', 'type' => 'float'], // float

        // Fetching Timestamp
        ['name' => 'created_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp
        ['name' => 'updated_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp

        // Market-related fields with doubled size
        ['name' => 'sector_id', 'type' => 'int', 'size' => 8], // size for int

        // Market Info json object
        ['name' => 'sector_info', 'type' => 'string', 'size' => 600], // Doubling size
    ],
];
