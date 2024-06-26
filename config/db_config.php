<?php
$db_engine = 'postgresql'; // Other Values: 'mysql' or 'both'

const POSTGRES_SERVER_DRIVER = 'mysql';
const MYSQL_SERVER_HOST = 'localhost';
const MYSQL_SERVER_PORT = 3306;
const MYSQL_SERVER_DB = ''; //'db_direct_wallet'; //'testdb';
const MYSQL_SERVER_USER = 'phpmyadmin'; //'root';
const MYSQL_SERVER_CHARSET = 'utf8mb4';
const MYSQL_SERVER_PWD = 'Passwd123';

//const POSTGRES_SERVER_DRIVER = 'pgsql'; // This constant is already defined internally by Swoole, so not required as of version 4.11.1
const POSTGRES_SERVER_HOST = 'localhost';
const POSTGRES_SERVER_PORT = 5432;
const POSTGRES_SERVER_DB = 'swooledb';
const POSTGRES_SERVER_USER = 'postgres';
const POSTGRES_SERVER_PWD = 'passwd123';
