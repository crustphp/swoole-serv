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
    'time_zone' => $_ENV['SW_TIMEZONE'] ?? 'Asia/Riyadh',
    'fds_reload_threshold' => intval($_ENV['SW_FDS_RELOAD_THRESHOLD=100'] ?? 40),
    'watch_excluded_folders' => $_ENV['SW_WATCH_EXCLUDED_FOLDERS'] ?? 'vendor, logs, process_pids, storage', // List of dirs you don't want to track for hot reload
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'staging_ip' => $_ENV['SW_STAGING_IP'],
    'production_ip' => $_ENV['SW_PRODUCTION_IP'],
    'privileged_fd_secret' => $_ENV['SW_PRIVILEGED_FD_SECRET'],
    'chatgpt_api_key' => $_ENV['SW_CHATGPT_API_KEY'],
    'most_active_data_fetching_timespan' => intval($_ENV['SW_MOST_ACTIVE_DATA_FETCHING_TIMESPAN'] ?? 300000),
    'api_req_timeout' => intval($_ENV['SW_API_REQ_TIMEOUT'] ?? 5),
    'api_calls_retry' => intval($_ENV['SW_API_CALLS_RETRY'] ?? 3),
    'api_token_time_span' => $_ENV['SW_API_AUTH_TOKEN_TIME_SPAN'] ?? '60000',
];
