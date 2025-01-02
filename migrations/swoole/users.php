<?php
// This is the sample Migration, You can remove it or modify it according to your needs
// Types allowed: int, float, string (Note: Type int and string requires additional 'size' attribute)
// More info on column Types: https://wiki.swoole.com/en/#/memory/table?id=column
// Min size is 64 bytes
return [
    'table_name' => 'users',
    'table_size' => 1024, // (be sure that this is not the actual number of rows, so always keep it higher than expected records this table can have)
    'is_nullable' => false, // Meta columns: `null` is added to all columns unless you set a value explicitly.
    'is_signed' => false, // Meta columns: `null` is added to int/float type columns unless you set a value explicitly.
    'columns' => [
        ['name' => 'id', 'type' => 'int', 'size' => 8],
        ['name' => 'name', 'type' => 'string', 'size' => 64, 'is_nullable' => true],
        ['name' => 'email', 'type' => 'string', 'size' => 64],
        ['name' => 'height', 'type' => 'float'],
        ['name' => 'age', 'type' => 'int', 'size' => 8, 'is_signed' => true, 'is_nullable' => true],
    ],
];
