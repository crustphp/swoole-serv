<?php

namespace App\Core\Services;

use Swoole\Coroutine\Redis;

class RedisService
{
    private $redis;

    /**
     * RedisService constructor.
     *
     * @param string $host
     * @param int $port
     * @param string|null $password
     */
    public function __construct()
    {
        $this->redis = new Redis();
    }

    /**
     * To connect with Redis Server
     *
     * @param  string $host
     * @param  int $port
     * @param  string|null $password
     * @return bool
     */
    public function connect(string $host = '127.0.0.1', int $port = 6379, ?string $password = null): bool
    {
        if (!$this->redis->connect($host, $port)) {
            output("Failed to connect to Redis");
            return false;
        }

        if ($password && !$this->redis->auth($password)) {
            output("Redis Authentication Failed");
            return false;
        }

        output("Connected to Redis Server");
        return true;
    }

    /**
     * Set a value in Redis.
     *
     * @param string $key    The Redis key.
     * @param mixed $value   The value to store (will be JSON-encoded).
     * @param int|array $options Expiration settings:
     *     - int: Expiration time in seconds (e.g., 3600)
     *     - array: Advanced options, e.g.:
     *         - ['nx', 'ex' => 10] → Set only if key doesn't exist, expires in 10 seconds
     *         - ['xx', 'px' => 1000] → Set only if key exists, expires in 1000 milliseconds
     *         - 'ex': Expiration time in seconds
     *         - 'px': Expiration time in milliseconds
     *         - 'nx': Set if key does not exist
     *         - 'xx': Set if key exists
     *
     * @return bool True if the value was set successfully, false otherwise.
     */
    public function set(string $key, mixed $value, int|array $options = 3600): bool
    {
        $value = json_encode($value);
        if ($this->redis->set($key, $value, $options)) {
            output("Data saved to Redis. Key: {$key}");
            return true;
        }
        output("Failed to save data to Redis.");
        return false;
    }

    /**
     * Get a value from Redis, unserializing it if necessary.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $result = $this->redis->get($key);
        if ($result !== false) {
            output("Retrieved Data for Key: {$key}");
            return json_decode($result, true);
        }
        output("Failed to retrieve data for Key: {$key}");
        return null;
    }

    /**
     * Delete a key from Redis.
     *
     * @param string $key
     * @return int
     */
    public function delete(string $key): int
    {
        return $this->redis->del((string) $key);
    }

    /**
     * Check if a key has data (i.e., is not null or empty).
     *
     * @param string $key
     * @return bool
     */
    public function hasData(string $key): bool
    {
        $value = $this->get((string) $key);
        return !is_null($value) && $value !== '';
    }

    /**
     * To close the Redis connection
     *
     * @return void
     */
    public function close(): void
    {
        $this->redis->close();
        output("Redis connection closed.");
    }

    /**
     * Destructor to automatically close the connection.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }
}
