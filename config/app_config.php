<?php

$swoole_ext = (extension_loaded('swoole') ? 1 : (extension_loaded('openswoole') ? 2: 0));
if (!$swoole_ext) {
    echo "Swoole Extension Missing.".PHP_EOL." Noticed From Within File: ".__FILE__.PHP_EOL." Line: ".__LINE__.PHP_EOL;
    exit;
}

return [
    'swoole_ext' => $swoole_ext,
    'swoole_app_debug' => intval($_ENV['SWOOLE_APP_DEBUG'] ?? 1),
    'db_connection_pool_size' => $_ENV['SWOOLE_DB_CONNECTION_POOL_SIZE'] ?? 4,
    'app_type_database_driven' => 0, //intval($_ENV['SWOOLE_APP_TYPE_DATABASE_DRIVEN'] ?? 1),
    'swoole_pg_db_key' => $_ENV['SWOOLE_PG_DB_KEY'] ?? 'pg',
    'swoole_mysql_db_key' => $_ENV['SWOOLE_MYSQL_DB_KEY'] ?? 'mysql',
    'swoole_timer_time1' => intval($_ENV['SWOOLE_TIMER_TIME1'] ?? 1000),
    'swoole_daemonize' => intval($_ENV['SWOOLE_DAEMONIZE'] ?? 0),
    'fds_table_name' => $_ENV['FDS_TABLE_NAME'] ?? 'fds_table',
    'fds_table_size' => intval($_ENV['FDS_TABLE_SIZE'] ?? 8192), // in Bytes
];

