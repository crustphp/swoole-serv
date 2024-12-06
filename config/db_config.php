<?php

return [
   'db_engine' => 'postgresql', // Other values: 'mysql' or 'both'
   'sw_connection_pool_heartbeat_time' => intval($_ENV['SW_CONNECTION_POOL_HEARTBEAT_TIME'] ?? 3), 

   'sw_mysql_server_driver' => $_ENV['SW_MYSQL_DB_DRIVER'] ?? 'mysql',
   'sw_mysql_server_host' => $_ENV['SW_MYSQL_DB_HOST'] ?? 'localhost',
   'sw_mysql_server_port' => $_ENV['SW_MYSQL_DB_PORT'] ?? '3306',
   'sw_mysql_server_db' => $_ENV['SW_MYSQL_DB_DATABASE'] ?? 'database_name',
   'sw_mysql_server_user' => $_ENV['SW_MYSQL_DB_USERNAME'] ?? 'database_user',
   'sw_mysql_server_passwd' => $_ENV['SW_MYSQL_DB_PASSWORD'] ?? 'database_password',
   'sw_mysql_server_charset' => $_ENV['SW_MYSQL_DB_CHARSET'] ?? 'utf8mb4',


    // 'sw_postgres_server_driver' => $_ENV['SW_PG_DB_DRIVER'], // This is already defined internally by Swoole, so not required as of version 4.11.1
    'sw_postgres_server_host' => $_ENV['SW_PG_DB_HOST'] ?? 'localhost',
    'sw_postgres_server_port' => $_ENV['SW_PG_DB_PORT'] ?? '5432',
    'sw_postgres_server_db' => $_ENV['SW_PG_DB_DATABASE'] ?? 'database_name',
    'sw_postgres_server_user' => $_ENV['SW_PG_DB_USERNAME'] ?? 'database_user',
    'sw_postgres_server_passwd' => $_ENV['SW_PG_DB_PASSWORD'] ?? 'database_password',
    'sw_postgres_client_timeout' => intval($_ENV['SW_PG_CLIENT_TIMEOUT'] ?? -1),

    // Connection Pool Size:
    'event_workers_db_connection_pool_size' => intval($_ENV['SW_EVENT_WORKERS_DB_CONNECTION_POOL_SIZE'] ?? 3),
    'custom_processes_db_connection_pool_size' => intval($_ENV['SW_CUSTOM_PROCESSES_DB_CONNECTION_POOL_SIZE'] ?? 3),
];
