<?php

return [
    'table_name' => 'tasi_companies_indicators',
    'table_size' => 1024, // table size
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

        // SP Indicators
        ['name' => 'iq_volume', 'type' => 'float'], // float
        ['name' => 'iq_float', 'type' => 'float'], // float
        ['name' => 'sp_turnover', 'type' => 'float'], // float

        // Refinitiv Day-Wise Indicators
        ['name' => 'uplimit', 'type' => 'float'], // float
        ['name' => 'lolimit', 'type' => 'float'], // float
        ['name' => 'life_high', 'type' => 'float'], // float
        ['name' => 'life_low', 'type' => 'float'], // float

        // Fetching Timestamp
        ['name' => 'created_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp
        ['name' => 'updated_at', 'type' => 'string', 'size' => 128], // Doubling Size for the timestamp

        // Company-related fields with doubled size
        ['name' => 'ric', 'type' => 'string', 'size' => 20], // Doubling the size
        ['name' => 'isin_code', 'type' => 'string', 'size' => 28], // Doubling the size
        ['name' => 'sp_comp_id', 'type' => 'string', 'size' => 32], // Doubling size
        ['name' => 'company_id', 'type' => 'int', 'size' => 8], // size for int

        // Company Info json object
        ['name' => 'company_info', 'type' => 'string', 'size' => 1150], // Doubling size
    ],
];
