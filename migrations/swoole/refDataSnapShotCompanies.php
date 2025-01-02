<?php

return [
    'table_name' => 'ref_data_snapshot_companies',
    'table_size' => 1024, // table size
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        // Indicators
        ['name' => 'cf_high', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'cf_last', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'cf_low', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'cf_volume', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'high_1', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'hst_close', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'low_1', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'netchng_1', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'num_moves', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'open_prc', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'pctchng', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'trdprc_1', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'turnover', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'yrhigh', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'yrlow', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'yr_pctch', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'cf_close', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'bid', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'ask', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'asksize', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float
        ['name' => 'bidsize', 'type' => 'float', 'is_signed' => true, 'is_nullable' => true], // float

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
