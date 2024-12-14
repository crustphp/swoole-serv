<?php

return [
    'table_name' => 'ref_data_snapshot_companies',
    'table_size' => 1024, // table size
    'columns' => [
        // Indicators
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

        // Company-related fields with doubled size
        ['name' => 'company_id', 'type' => 'int', 'size' => 8], // size for int
        ['name' => 'en_long_name', 'type' => 'string', 'size' => 128], // Doubling size
        ['name' => 'sp_comp_id', 'type' => 'string', 'size' => 32], // Doubling size
        ['name' => 'en_short_name', 'type' => 'string', 'size' => 32], // Doubling size
        ['name' => 'symbol', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'isin_code', 'type' => 'string', 'size' => 28], // Doubling the size
        ['name' => 'ar_long_name', 'type' => 'string', 'size' => 128], // Doubling the size
        ['name' => 'ar_short_name', 'type' => 'string', 'size' => 32], // Doubling the size
        ['name' => 'ric', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'logo', 'type' => 'string', 'size' => 600], // String
        ['name' => 'market_id', 'type' => 'int', 'size' => 8], // int
        ['name' => 'market_name', 'type' => 'string', 'size' => 128], // String
    ],
];
