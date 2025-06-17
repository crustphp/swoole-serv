<?php

return [
    'table_name' => 'markets_historical_indicators',
    'table_size' => 1400, // table size
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        // Unique Key
        ['name' => 'id', 'type' => 'int', 'size' => 8], // Doubling the size

        // Refinitiv Indicators
        ['name' => 'high_1', 'type' => 'float'], // float
        ['name' => 'low_1', 'type' => 'float'], // float
        ['name' => 'num_moves', 'type' => 'float'], // float
        ['name' => 'open_prc', 'type' => 'float'], // float
        ['name' => 'trdprc_1', 'type' => 'float'], // float
        ['name' => 'acvol_uns', 'type' => 'float'], // float

        // Fetching Timestamp
        ['name' => 'refinitiv_datetime', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp
        ['name' => 'created_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp
        ['name' => 'updated_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp

        // Market-related fields with doubled size
        ['name' => 'refinitiv_universe', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'market_id', 'type' => 'int', 'size' => 8], // size for int

        // Market Info json object
        ['name' => 'market_info', 'type' => 'string', 'size' => 400], // Doubling size
    ],
];
