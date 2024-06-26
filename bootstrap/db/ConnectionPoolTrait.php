<?php

namespace DB;
use Smf\ConnectionPool\ConnectionPool;

trait ConnectionPoolTrait
{
    /**
     * @var ConnectionPool[] $pools
     */
    protected static $pools = [];

    /**
     * Add a connection pool
     * @param string $key
     * @param ConnectionPool $pool
     */
    public function addConnectionPool(int|string $key, ConnectionPool $pool)
    {
        self::$pools[$key] = $pool;
    }

    /**
     * Get a connection pool by key
     * @param string $key
     * @return ConnectionPool
     */
    public function getConnectionPool(int | string $key): ConnectionPool
    {
        return self::$pools[$key];
    }

    /**
     * Close the connection by key
     * @param string $key
     * @return bool
     */
    public function closeConnectionPool(int|string $key)
    {
        $bool = self::$pools[$key]->close();
        unset(self::$pools[$key]);
        return $bool;
    }

    /**
     * Close all connection pools
     */
    public function closeConnectionPools()
    {
        foreach (self::$pools as $pool) {
            $pool->close();
        }
    }
}
