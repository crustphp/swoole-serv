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

use Swoole\Coroutine as Co;
use Swoole\Coroutine\Channel;

class ConnectionPool
{
    public const DEFAULT_SIZE = 64;

    protected ?Channel $pool;

    /** @var callable */
    protected $constructor;

    protected int $size;

    protected int $num = 0;

    protected int $inUse = 0;

    public function __construct(callable $constructor, int $size = self::DEFAULT_SIZE, protected ?string $proxy = null)
    {
        $this->pool        = new Channel($this->size = $size);
        $this->constructor = $constructor;

        // Heartbeat Checks if connection is alive, if not than unset and remove it from pool
        // Commented Heartbeat() below as we are doing connection checks in DbFacade's query Method
        // $this->heartbeat();
    }

    public function fill(): void
    {
        while ($this->size > $this->num) {
            $this->make();
        }
    }

    /**
     * Get a connection from the pool.
     *
     * @param float $timeout > 0 means waiting for the specified number of seconds. other means no waiting.
     * @param bool Discard the connection with a new replaced connection.
     * @return mixed|false Returns a connection object from the pool, or false if the pool is full and the timeout is reached.
     */
    public function get(float $timeout = -1, bool $replace = false)
    {
        if ($this->pool === null) {
            throw new \RuntimeException('Pool has been closed');
        }

        // If its the case for replace the connection
        if ($replace && $this->num != 0) {
            $this->put(null);
        } 
        // Default case If pool is empty and we a space/num to create a new connection then we create it.
        else if ($this->pool->isEmpty() && $this->num < $this->size) {
            $this->make();
        }

        $connection = $this->pool->pop($timeout);
        if ($connection) {
            $this->inUse++;
        }

        return $connection;
    }

    public function put($connection, $isNew = false): void
    {
        if ($this->pool === null) {
            return;
        }

        if ($connection !== null) {
            $this->pool->push($connection);            
        } else {
            /* connection broken */
            $this->num -= 1;
            $this->make();
        }

        // If its not the new connection than decrement the inUse after putting it back to pool
        if (!$isNew) {
            $this->inUse--;
        }
    }

    public function close(): void
    {
        $this->num = $this->size;

        if ($this->pool) {
            do {
                // From OpenSwoole ClientPool
                // if ($this->inUse > 0) {
                //     co::sleep(1);
                //     continue;
                // }
                
                if (!$this->pool->isEmpty()) {
                    $client = $this->pool->pop();
                    if (method_exists($client, 'close')) {
                        $client->close();
                    }
                    else {
                        unset($client);
                    }
                }
            }
            while (!($this->pool->isEmpty() && $this->inUse == 0));
    
            $this->pool->close();
            $this->pool = null;
            $this->num  = 0;
        }        
    }

    protected function make(): void
    {
        $this->num++;
        try {
            if ($this->proxy) {
                $connection = new $this->proxy($this->constructor);
            } else {
                $constructor = $this->constructor;
                $connection  = $constructor();
            }
        } catch (\Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }
        $this->put($connection, true);
    }

    /**
     * This function needs to be investegated (Reference: it was taken from openswoole ClientPool class) 
     * and modified for our use-case (Inpired By: https://github.com/swoole/swoole-src/issues/4131)
     *
     * @return void
     */
    protected function heartbeat()
    {
        Co::create(function () {
            while ($this->pool) {
                try {
                    Co::sleep(config('db_config.sw_connection_pool_heartbeat_time'));

                    // To Prevent Empty/Closed Pool Exception on shutdown
                    if ($this->pool == null) {
                        continue;
                    }

                    $client = $this->get();
                    // $client->heartbeat(); // OpenSwoole client->heartbeat | in swoole we don't have this

                    if ($client == null) {
                        throw new \Exception('Database Connection client is null');
                    }

                    $result = $client->query('SELECT 1');

                    // Error Codes PostgreSQL: https://www.postgresql.org/docs/current/errcodes-appendix.html
                    // Error Codes Swoole: https://wiki.swoole.com/en/#/other/errno?id=swoole-error-code-list
                    // Error Codes Swoole Related to Timeout: https://wiki.swoole.com/en/#/coroutine_client/http_client?id=errcode
                    $pgConnErrCodes = ['08003', '57P03', '57014', '08006', '25P03', '57P05'];
                    $mysqlConnErrCodes = [2013, 2006];
                    $swooleConnErrCodes = [110, 111, 112, 8503];

                    if (
                        $result == false ||
                        in_array($client->errCode, $pgConnErrCodes) ||
                        in_array($client->errCode, $mysqlConnErrCodes) ||
                        in_array($client->errCode, $swooleConnErrCodes)
                    ) {
                        throw new \Exception('Database Connection Error: ' . $client->errCode . ' -> ' . $client->error);
                    }

                    // Other Preventive Measure based on https://wiki.swoole.com/en/#/coroutine_client/http_client?id=extra-options
                    if (isset($client->statusCode) && ($client->statusCode == -1 || $client->statusCode == -2)) {
                        throw new \Exception('Server has closed the DB Client connection or request times out');
                    }

                    // If there are no errors put back the client connection
                    $this->put($client);
                } catch (\Throwable $e) {
                    // Discard the connection and decrement the connections number
                    $client = null;
                    $result = null;
                    unset($client);
                    unset($result);

                    $this->num--;

                    // Log the exception
                    output($e);
                }
            }
        });
    }

    /**
     * Returns the number of connections currently the pool has
     *
     * @return int
     */
    public function getNum(): int
    {
        return $this->num;
    }

    /**
     * Returns the size of the Connection Pool
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * This function removes the client
     *
     * @param  mixed $client
     * @return void
     */
    public function removeClient(mixed &$client): void
    {
        $client = null;
        unset($client);

        $this->num -= 1;
    }

    /**
     * This function checks if the Client Connection is stable and working.
     *
     * @param  mixed $client
     * @return bool
     */
    public function isClientConnected(mixed $client): bool
    {
        try {
            // Error Codes PostgreSQL: https://www.postgresql.org/docs/current/errcodes-appendix.html
            // Error Codes Swoole: https://wiki.swoole.com/en/#/other/errno?id=swoole-error-code-list
            // Error Codes Swoole Related to Timeout: https://wiki.swoole.com/en/#/coroutine_client/http_client?id=errcode
            $pgConnErrCodes = ['08003', '57P03', '57014', '08006', '25P03', '57P05'];
            $mysqlConnErrCodes = [2013, 2006];
            $swooleConnErrCodes = [110, 111, 112, 8503];

            if (
                in_array($client->errCode, $pgConnErrCodes) ||
                in_array($client->errCode, $mysqlConnErrCodes) ||
                in_array($client->errCode, $swooleConnErrCodes)
            ) {
                return false;
            }

            // Other Preventive Measure based on https://wiki.swoole.com/en/#/coroutine_client/http_client?id=extra-options
            if (isset($client->statusCode) && ($client->statusCode == -1 || $client->statusCode == -2)) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Log the exception
            output($e);

            return false;
        }
    }

    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }
}
