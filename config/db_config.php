<?php
global $db_engine;
$db_engine = 'postgresql'; // Other Values: 'mysql' or 'both'

global $swMysqlServerDriver;
global $swMysqlServerHost;
global $swMysqlServerPort;
global $swMysqlServerDb;
global $swMysqlServerUser;
global $swMysqlServerPasswd;
global $swMysqlServerCharset;

$swMysqlServerDriver = ''; // $_ENV['SWOOLE_MYSQL_DB_DRIVER'] //'mysql';
$swMysqlServerHost = $_ENV['SWOOLE_MYSQL_DB_HOST'];
$swMysqlServerPort = $_ENV['SWOOLE_MYSQL_DB_PORT'];
$swMysqlServerDb = $_ENV['SWOOLE_MYSQL_DB_DATABASE'];
$swMysqlServerUser= $_ENV['SWOOLE_MYSQL_DB_USERNAME']; //'root';
$swMysqlServerPasswd = $_ENV['SWOOLE_MYSQL_DB_PASSWORD'];
$swMysqlServerCharset = $_ENV['SWOOLE_MYSQL_DB_CHARSET'];


global $swPostgresServerHost;
global $swPostgresServerPort;
global $swPostgresServerDB;
global $swPostgresServerUser;
global $swPostgresServerPasswd;

//$swPostgresServerDriver = $_ENV['DB_CONNECTION']; // This is already defined internally by Swoole, so not required as of version 4.11.1
$swPostgresServerHost = $_ENV['SWOOLE_PG_DB_HOST'];
$swPostgresServerPort = $_ENV['SWOOLE_PG_DB_PORT'];
$swPostgresServerDB = $_ENV['SWOOLE_PG_DB_DATABASE'];
$swPostgresServerUser = $_ENV['SWOOLE_PG_DB_USERNAME'];
$swPostgresServerPasswd = $_ENV['SWOOLE_PG_DB_PASSWORD'];
