<?php

return [
    'table_name' => 'job_runs', // Proper name indicating it's for storing job run timestamps
    'table_size' => 1024, // Size can be smaller depending on how many job runs you're tracking
    'columns' => [
        ['name' => 'job_run_at', 'type' => 'string', 'size' => 128]
    ],
];
