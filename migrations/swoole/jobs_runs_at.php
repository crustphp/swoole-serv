<?php

return [
    'table_name' => 'jobs_runs_at', // Proper name indicating it's for storing job run timestamps
    'table_size' => 1024, // Size can be smaller depending on how many job runs you're tracking
    'columns' => [
        ['name' => 'job_name', 'type' => 'string', 'size' => 256],
        ['name' => 'job_run_at', 'type' => 'string', 'size' => 128],
    ],
];
