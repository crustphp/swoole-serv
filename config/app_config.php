<?php

$swoole_ext = (extension_loaded('swoole') ? 1 : (extension_loaded('openswoole') ? 2: 0));
if (!$swoole_ext) {
    echo "Swoole Extension Missing.".PHP_EOL." Noticed From Within File: ".__FILE__.PHP_EOL." Line: ".__LINE__.PHP_EOL;
    exit;
}

return [
    'swoole_ext' => $swoole_ext,
    'swoole_app_debug' => intval($_ENV['SW_APP_DEBUG'] ?? 1),
    'app_type_database_driven' => intval($_ENV['SW_APP_TYPE_DATABASE_DRIVEN'] ?? 1),
    'swoole_pg_db_key' => $_ENV['SW_PG_DB_KEY'] ?? 'pg',
    'swoole_mysql_db_key' => $_ENV['SW_MYSQL_DB_KEY'] ?? 'mysql',
    'swoole_timer_time1' => intval($_ENV['SW_TIMER_TIME1'] ?? 1000),
    'swoole_daemonize' => intval($_ENV['SW_DAEMONIZE'] ?? 0),
    'app_url' => $_ENV['SW_APP_URL'] ?? 'https://muasherat.devdksa.com',
    'most_active_refinitive_timespan' => intval($_ENV['SW_MOST_ACTIVE_REFINITIVE_TIMESPAN'] ?? 300000),
    'time_zone' => $_ENV['SW_TIMEZONE'] ?? 'Asia/Riyadh',
    'refinitiv_req_timeout' => intval($_ENV['SW_REFINITIV_REQ_TIMEOUT'] ?? 5),
    'fds_reload_threshold' => intval($_ENV['SW_FDS_RELOAD_THRESHOLD=100'] ?? 40),
    'refinitv_retry' => intval($_ENV['SW_REFINITIV_RETRY'] ?? 3),
    'watch_excluded_folders' => $_ENV['SW_WATCH_EXCLUDED_FOLDERS'] ?? 'vendor, logs, process_pids', // List of dirs you don't want to track for hot reload
    'refinitive_pricing_snapshot_url' => $_ENV['SW_REFINITIVE_PRICING_SNAPSHOT_URL'] ?? 'https://api.refinitiv.com/data/pricing/snapshots/v1/',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'refinitive_staging_token_endpoint_key' => $_ENV['SW_REFINITIVE_STAGING_TOKEN_ENDPOINT_KEY'],
    'staging_ip' => $_ENV['SW_STAGING_IP'],
    'refinitive_production_token_endpoint_key' => $_ENV['SW_REFINITIVE_PRODUCTION_TOKEN_ENDPOINT_KEY'] ?? 'auqSGJ89Kp3DGJ*!%4',
    'refinitive_token_time_span' => $_ENV['SW_REFINITIVE_TOKEN_TIME_SPAN'] ?? '60000',
    'production_ip' => $_ENV['SW_PRODUCTION_IP'],
    'privileged_fd_secret' => $_ENV['SW_PRIVILEGED_FD_SECRET'] ?? 'YpGN0qwO80',
    'spglobal_ftp_url' => $_ENV['SPGLOBAL_FTP_URL'] ?? 'sftp2.spglobal.com',
    'spglobal_username' => $_ENV['SPGLOBAL_USERNAME'] ?? 'Muas2321PC',
    'spglobal_password' => $_ENV['SPGLOBAL_PASSWORD'] ?? '1j1c4Z$05',
    'ref_fields' => $_ENV['SW_REF_FIELDS'],
];
