<?php

return [
   'db_engine' => 'postgresql', // Other values: 'mysql' or 'both'

   'sw_mysql_server_driver' => $_ENV['SWOOLE_MYSQL_DB_DRIVER'] ?? 'mysql',
   'sw_mysql_server_host' => $_ENV['SWOOLE_MYSQL_DB_HOST'] ?? 'localhost',
   'sw_mysql_server_port' => $_ENV['SWOOLE_MYSQL_DB_PORT'] ?? '3306',
   'sw_mysql_server_db' => $_ENV['SWOOLE_MYSQL_DB_DATABASE'] ?? 'database_name',
   'sw_mysql_server_user' => $_ENV['SWOOLE_MYSQL_DB_USERNAME'] ?? 'database_user',
   'sw_mysql_server_passwd' => $_ENV['SWOOLE_MYSQL_DB_PASSWORD'] ?? 'database_password',
   'sw_mysql_server_charset' => $_ENV['SWOOLE_MYSQL_DB_CHARSET'] ?? 'utf8mb4',


    // 'sw_postgres_server_driver' => $_ENV['DB_CONNECTION'], // This is already defined internally by Swoole, so not required as of version 4.11.1
    'sw_postgres_server_host' => $_ENV['SWOOLE_PG_DB_HOST'] ?? 'localhost',
    'sw_postgres_server_port' => $_ENV['SWOOLE_PG_DB_PORT'] ?? '5432',
    'sw_postgres_server_db' => $_ENV['SWOOLE_PG_DB_DATABASE'] ?? 'database_name',
    'sw_postgres_server_user' => $_ENV['SWOOLE_PG_DB_USERNAME'] ?? 'database_user',
    'sw_postgres_server_passwd' => $_ENV['SWOOLE_PG_DB_PASSWORD'] ?? 'database_password',

    // Connection Pool Size:
    'event_workers_db_connection_pool_size' => intval($_ENV['SW_EVENT_WORKERS_DB_CONNECTION_POOL_SIZE'] ?? 3),
    'custom_processes_db_connection_pool_size' => intval($_ENV['SW_CUSTOM_PROCESSES_DB_CONNECTION_POOL_SIZE'] ?? 3),
];
