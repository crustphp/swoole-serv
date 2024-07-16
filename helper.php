<?php
if (!function_exists('dump')){
    function dump($var)
    {
        return highlight_string("<?php\n\$array = " . var_export($var, true) . ";", true);
    }
}

function makePoolKey($id, $dbEngine) {
    global $swoole_pg_db_key;
    global $swoole_mysql_db_key;
    $dbEngine = strtolower($dbEngine);
    if ($dbEngine == 'postgres') {
        return $swoole_pg_db_key.$id;
    } else if ($dbEngine == 'mysql') {
        return $swoole_mysql_db_key.$id;
    } else {
        throw new \RuntimeException('Inside helper.php->function makePoolKey(), Argument $dbEngine should either be \'postgres\' or \'mysql\'.');
    }
}
