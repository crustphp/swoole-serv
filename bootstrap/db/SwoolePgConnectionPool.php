<?php

/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace DB;

use Swoole\Coroutine\PostgreSQL;
use DB\ConnectionPool;
use DB\SwoolePgConfig;

/**
 * @method \mysqli|MysqliProxy get()
 * @method void put(mysqli|MysqliProxy $connection)
 */
class SwoolePgConnectionPool extends ConnectionPool
{
    public function __construct(protected SwoolePgConfig $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function() {
            $pg = new PostgreSQL();
            $conn = $pg->connect($this->config->getConnectionString());
            if (!$conn) {
                var_dump($pg->error);
                return;
            }
            return $pg;
        }, $size);
    }
}
