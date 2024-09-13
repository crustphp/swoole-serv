<?php
$swoole_config = [
    'coroutine_settings' => [
        'max_concurrency' => 100,
        'max_coroutine' => 10000,

        // Can also be set using Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);
        //'hook_flags' => SWOOLE_HOOK_ALL,
    ],

    'server_settings' => [
        'daemonize' => config('swoole_daemonize') ?? 0,

        // 'user' => 'www-data',
        // 'group' => 'www-data',

        'pid_file' => dirname(__DIR__) . '/server.pid',

        // 'chroot' => '/data/server/',
        // 'open_cpu_affinity' => true,
        // 'cpu_affinity_ignore' => [0, 1],

        'dispatch_mode' => 2,
        'max_request' => 100000,
        'open_tcp_nodelay' => true,
        'reload_async' => true,
        'max_wait_time' => 60,
        'enable_reuse_port' => true,
        'enable_coroutine' => true,
        'enable_static_handler' => true,
        'static_handler_locations' => ['/static', '/app/images', '/releases'],
        // 'http_compression'      => false, This Key already exists Compression config
        'buffer_output_size' => 1024 * 1024 * 1024,
        'reactor_num' => 16,
        'worker_num' => 4, // Each worker holds a connection pool
        'task_worker_num' => 8,  // The amount of task workers to start
        'task_enable_coroutine' => true,

        // Protocol
        'open_http_protocol' => false, // Being set in setDefault function in sw_service.php

        // HTTP Server
        'http_parse_post' => true,
        'http_parse_cookie' => true,
        'upload_tmp_dir' => '/tmp',

        // 'open_mqtt_protocol' => true,

        // Websocket
        'open_websocket_protocol' => false, // Being set in setDefault function in sw_service.php
        'websocket_compression' => true,
        'open_websocket_close_frame' => true,
        'open_websocket_ping_frame' => true, // added from v4.5.4
        'open_websocket_pong_frame' => true, // added from v4.5.4
        // 'heartbeat_idle_time' => 5,
        // 'heartbeat_check_interval' => 2,

        // HTTP2:
        // These configurations below are already initialized in Swoole and are not configurable, however these can be configured in OpenSwoole 4.11.1 as below
        // 'open_http2_protocol' => false,
        // 'http2_header_table_size' => 4095,
        // 'http2_initial_window_size' => 65534,
        // 'http2_max_concurrent_streams' => 1281,
        // 'http2_max_frame_size' => 16383,
        // 'http2_max_header_list_size' => 4095,
        // OR
        // 'http2_header_table_size' => 2048,
        // 'http2_enable_push' => false,
        // 'http2_max_concurrent_streams' => 128,
        // 'http2_init_window_size' => 2 ** 24,
        // 'http2_max_frame_size' => 65536,
        // 'http2_max_header_list_size' => 2 ** 24,

        // Compression
        'http_compression' => false,
        'http_compression_level' => 3, // 1 - 9
        'compression_min_length' => 20,

        // Static Files
        'document_root' => __DIR__ . '/public',
        'enable_static_handler' => true,
        'static_handler_locations' => ['/static', '/app/images'],
        'http_index_files' => ['index.html', 'index.txt'],

        // Source File Reloading
        // 'reload_async' => true,
        // 'max_wait_time' => 3,

        // Logging
        // Ref: https://openswoole.com/docs/modules/swoole-server/configuration#log_level
        'log_level' => SWOOLE_LOG_ERROR, //SWOOLE_LOG_DEBUG
        //'log_level' => SWOOLE_LOG_DEBUG,
        'log_file' => dirname(__DIR__) . '/logs/swoole.log',
        'log_rotation' => SWOOLE_LOG_ROTATION_HOURLY,
        'log_date_format' => '%Y-%m-%d %H:%M:%S',
        'log_date_with_microseconds' => true,

        // Enable trace logs
        // Ref: https://openswoole.com/docs/modules/swoole-server/configuration#trace_flags
        'trace_flags' => SWOOLE_TRACE_ALL,
    ],
];

// If extension swoole is loaded
if (extension_loaded('swoole')) {
    $swoole_config['server_settings'] ['enable_preemptive_scheduler'] = true;
    $swoole_config['server_settings'] ['enable_deadlock_check'] = true;
}

return $swoole_config;

/*
 * Swoole Server Configurations
 * Ref: https://openswoole.com/docs/modules/swoole-server/configuration
$server->set([

    // Process
    'daemonize' => 1,
    'user' => 'www-data',
    'group' => 'www-data',
    'chroot' => '/data/server/',
    'open_cpu_affinity' => true,
    'cpu_affinity_ignore' => [0, 1],
    'pid_file' => __DIR__.'/server.pid',

    // Server
    'reactor_num' => 8,
    'worker_num' => 2,
    'message_queue_key' => 'mq1',
    'dispatch_mode' => 2,
    'discard_timeout_request' => true,
    'dispatch_func' => 'my_dispatch_function',

    // Worker
    'max_request' => 0,
    'max_request_grace' => $max_request / 2,

    // HTTP Server max execution time, since v4.8.0
    'max_request_execution_time' => 30, // 30s

    // Task worker
    'task_ipc_mode' => 1,
    'task_max_request' => 100,
    'task_tmpdir' => '/tmp',
    'task_worker_num' => 8,
    'task_enable_coroutine' => true,
    'task_use_object' => true,

    // Logging
    'log_level' => 1,
    'log_file' => '/data/swoole.log',
    'log_rotation' => SWOOLE_LOG_ROTATION_DAILY,
    'log_date_format' => '%Y-%m-%d %H:%M:%S',
    'log_date_with_microseconds' => false,
    'request_slowlog_file' => false,

    // Enable trace logs
    'trace_flags' => SWOOLE_TRACE_ALL,

    // TCP
    'input_buffer_size' => 2097152,
    'buffer_output_size' => 32*1024*1024, // byte in unit
    'tcp_fastopen' => false,
    'max_conn' => 1000,
    'tcp_defer_accept' => 5,
    'open_tcp_keepalive' => true,
    'open_tcp_nodelay' => false,
    'pipe_buffer_size' => 32 * 1024*1024,
    'socket_buffer_size' => 128 * 1024*1024,

    // Kernel
    'backlog' => 512,
    'kernel_socket_send_buffer_size' => 65535,
    'kernel_socket_recv_buffer_size' => 65535,

    // TCP Parser
    'open_eof_check' => true,
    'open_eof_split' => true,
    'package_eof' => '\r\n',
    'open_length_check' => true,
    'package_length_type' => 'N',
    'package_body_offset' => 8,
    'package_length_offset' => 8,
    'package_max_length' => 2 * 1024 * 1024, // 2MB
    'package_length_func' => 'my_package_length_func',

    // Coroutine
    'enable_coroutine' => true,
    'max_coroutine' => 3000,
    'send_yield' => false,

    // tcp server
    'heartbeat_idle_time' => 600,
    'heartbeat_check_interval' => 60,
    'enable_delay_receive' => true,
    'enable_reuse_port' => true,
    'enable_unsafe_event' => true,

    // Protocol
    'open_http_protocol' => true,
    'open_http2_protocol' => true,
    'open_websocket_protocol' => true,
    'open_mqtt_protocol' => true,

    // HTTP2
    'http2_header_table_size' => 4095,
    'http2_initial_window_size' => 65534,
    'http2_max_concurrent_streams' => 1281,
    'http2_max_frame_size' => 16383,
    'http2_max_header_list_size' => 4095,

    // SSL
    'ssl_cert_file' => __DIR__ . '/config/ssl.cert',
    'ssl_key_file' => __DIR__ . '/config/ssl.key',
    'ssl_ciphers' => 'ALL:!ADH:!EXPORT56:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv2:+EXP',
    'ssl_method' => SWOOLE_SSLv3_CLIENT_METHOD, // removed from v4.5.4
    'ssl_protocols' => 0, // added from v4.5.4
    'ssl_verify_peer' => false,
    'ssl_sni_certs' => [
        "cs.php.net" => [
            'ssl_cert_file' => __DIR__ . "/config/sni_server_cs_cert.pem",
            'ssl_key_file' => __DIR__ . "/config/sni_server_cs_key.pem"
        ],
        "uk.php.net" => [
            'ssl_cert_file' => __DIR__ . "/config/sni_server_uk_cert.pem",
            'ssl_key_file' => __DIR__ . "/config/sni_server_uk_key.pem"
        ],
        "us.php.net" => [
            'ssl_cert_file' => __DIR__ . "/config/sni_server_us_cert.pem",
            'ssl_key_file' =>  __DIR__ . "/config/sni_server_us_key.pem",
        ],
    ],

    // Static Files
    'document_root' => __DIR__ . '/public',
    'enable_static_handler' => true,
    'static_handler_locations' => ['/static', '/app/images'],
    'http_index_files' => ['index.html', 'index.txt'],

    // Source File Reloading
    'reload_async' => true,
    'max_wait_time' => 30,

    // HTTP Server
    'http_parse_post' => true,
    'http_parse_cookie' => true,
    'upload_tmp_dir' => '/tmp',

    // Compression
    'http_compression' => true,
    'http_compression_level' => 3, // 1 - 9
    'compression_min_length' => 20,


    // Websocket
    'websocket_compression' => true,
    'open_websocket_close_frame' => false,
    'open_websocket_ping_frame' => false, // added from v4.5.4
    'open_websocket_pong_frame' => false, // added from v4.5.4

    // TCP User Timeout
    'tcp_user_timeout' => 0,

    // DNS Server
    'dns_server' => '8.8.8.8:53',
    'dns_cache_refresh_time' => 60,
    'enable_preemptive_scheduler' => 0,

    'open_fastcgi_protocol' => 0,
    'open_redis_protocol' => 0,

    'stats_file' => './stats_file.txt', // removed from v4.9.0

    'enable_object' => true,

]);
 */


###############################################33
/*
 * Co-routine configuration Options
 * Ref: https://openswoole.com/docs/modules/swoole-coroutine-set
$options = [
    'max_concurrency' => 0,
    'max_coroutine' => 4096,
    'stack_size' => 2 * 1024 * 1024,
    'socket_connect_timeout' => 1,
    'socket_timeout' => -1,
    'socket_read_timeout' => -1,
    'socket_write_timeout' => -1,
    'log_level' => SWOOLE_LOG_INFO,
    'hook_flags' => SWOOLE_HOOK_ALL,
    'trace_flags' => SWOOLE_TRACE_ALL,
    'dns_cache_expire' => 60,
    'dns_cache_capacity' => 1000,
    'dns_server' => '8.8.8.8',
    'display_errors' => false,
    'aio_core_worker_num' => 10,
    'aio_worker_num' => 10,
    'aio_max_wait_time' => 1,
    'aio_max_idle_time' => 1,
    'exit_condition' => function() {
        return Swoole\Coroutine::stats()['coroutine_num'] === 0;
    },
];

Swoole\Coroutine::set($options);
 */
