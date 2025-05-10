<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Initialize the Redis client and ensure the connection is successful.
 *
 * @return Redis
 */
function setupRedis(): Redis
{
    $redis = new Redis();

    try {
        $redis->connect('redis', 6379); // Adjust host and port if necessary
        echo "Connected to Redis!\n";
    } catch (RedisException $e) {
        echo 'Failed to connect to Redis: ' . $e->getMessage();
        exit(1);
    }

    return $redis;
}