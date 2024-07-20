<?php
global $swoole_ext;
$swoole_ext = (extension_loaded('swoole') ? 1 : (extension_loaded('openswoole') ? 2: 0));
if (!$swoole_ext) {
    echo "Swoole Extension Missing.".PHP_EOL." Noticed From Within File: ".__FILE__.PHP_EOL." Line: ".__LINE__.PHP_EOL;
    exit;
}
global $db_connection_pool_size;
$db_connection_pool_size = (isset($_ENV['DB_CONNECTION_POOL_SIZE']) ? $_ENV['DB_CONNECTION_POOL_SIZE'] : 4);

global $app_type_database_driven;
$app_type_database_driven= $_ENV['APP_TYPE_DATABASE_DRIVEN'] ?? false ;

global $swoole_pg_db_key;
$swoole_pg_db_key=($_ENV['SWOOLE_PG_DB_KEY'] ?? 'pg');

global $swoole_mysql_db_key;
$swoole_mysql_db_key=($_ENV['SWOOLE_MYSQL_DB_KEY'] ?? 'mysql');
