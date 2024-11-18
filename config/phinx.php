<?php
// This is the configuration file of phinix php
// Docs: https://book.cakephp.org/phinx/0/en/configuration.html
// Environment > Adapters Docs: https://book.cakephp.org/phinx/0/en/configuration.html#supported-adapters

// Setting the basepath and autoloading the dependencies
$basePath = dirname(__DIR__, 1);
require_once $basePath . '/vendor/autoload.php';

// Setting up Env for using $_ENV variable
require_once $basePath . '/includes/LoadEnv.php';

// set the default timezone
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'Asia/Riyadh'); // We need to test it after making our custom migration commands

// Configurations
$configuration =
    [
        'paths' => [
            'migrations' => '%%PHINX_CONFIG_DIR%%/../migrations/Database',
            'seeds' => '%%PHINX_CONFIG_DIR%%/../seeders/Database'
        ],
        'environments' => [
            'default_migration_table' => 'phinxlog',
            'default_environment' => $_ENV['SW_PHINX_ENV'] ?? 'db',
            'db' => [
                'adapter' => 'pgsql', // Docs: https://book.cakephp.org/phinx/0/en/configuration.html#supported-adapters
                'host' => $_ENV['SWOOLE_PG_DB_HOST'] ?? 'localhost',
                'name' => $_ENV['SWOOLE_PG_DB_DATABASE'] ?? 'database_name',
                'user' => $_ENV['SWOOLE_PG_DB_USERNAME'] ?? 'database_user',
                'pass' => $_ENV['SWOOLE_PG_DB_PASSWORD'] ?? 'database_password',
                'port' => $_ENV['SWOOLE_PG_DB_PORT'] ?? '5432',
                'charset' => 'utf8',
            ],
        ],
        'version_order' => 'creation' // creation or execution (Docs: https://book.cakephp.org/phinx/0/en/configuration.html#version-order)
    ];

return $configuration;
