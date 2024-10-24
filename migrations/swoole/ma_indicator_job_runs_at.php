<?php

return [
    'table_name' => 'ma_indicator_job_runs_at', // Proper name indicating it's for storing job run timestamps
    'table_size' => 1024, // Size can be smaller depending on how many job runs you're tracking
    'columns' => [
        ['name' => 'job_run_at', 'type' => 'string', 'size' => 128]
    ],
];
