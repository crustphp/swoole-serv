<?php
// This is the sample Migration, You can remove it or modify it according to your needs
// Types allowed: int, float, string (Note: Type int and string requires additional 'size' attribute)
// More info on column Types: https://wiki.swoole.com/en/#/memory/table?id=column
// Min size is 64 bytes
return [
    'table_name' => 'ref_token_sw',
    'table_size' => 2, // be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        ['name' => 'id', 'type' => 'int', 'size' => 8],
        ['name' => 'access_token', 'type' => 'string', 'size' => 6000],
        ['name' => 'refresh_token', 'type' => 'string', 'size' => 2000],
        ['name' => 'expires_in', 'type' => 'int', 'size' => 8],
        ['name' => 'created_at', 'type' => 'string', 'size' => 128],
        ['name' => 'updated_at', 'type' => 'string', 'size' => 128],
        ['name' => 'updated_by_process', 'type' => 'string', 'size' => 128],
    ],
];
