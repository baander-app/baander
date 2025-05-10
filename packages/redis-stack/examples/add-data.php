<?php

require_once __DIR__ . '/setup-redis.php';

$redis = setupRedis();

/**
 * Flush Redis database to start fresh.
 */
echo "Flushing Redis...\n";
$redis->flushAll();

/**
 * Create a search index for products.
 * This schema includes fields for product name, description, price, and brand.
 */
echo "Creating product index...\n";
$redis->rawCommand('FT.CREATE', 'products', 'ON', 'HASH', 'SCHEMA',
    'title', 'TEXT',
    'description', 'TEXT',
    'price', 'NUMERIC',
    'brand', 'TAG'
);

/**
 * Add products data to Redis.
 */
echo "Adding product data...\n";
$productData = [
    [
        'title' => 'Gaming Laptop',
        'description' => 'A powerful gaming laptop with the latest GPU.',
        'price' => 1500,
        'brand' => 'Alienware'
    ],
    [
        'title' => 'Office Laptop',
        'description' => 'Perfect for office tasks and productivity.',
        'price' => 800,
        'brand' => 'Dell'
    ],
    [
        'title' => 'Gaming Monitor',
        'description' => 'A high-refresh-rate monitor for gamers.',
        'price' => 300,
        'brand' => 'Samsung'
    ],
    [
        'title' => 'Wireless Keyboard',
        'description' => 'A sleek and comfortable wireless keyboard.',
        'price' => 50,
        'brand' => 'Logitech'
    ],
    [
        'title' => 'Smartphone',
        'description' => 'The latest smartphone with cutting-edge features.',
        'price' => 1000,
        'brand' => 'Samsung'
    ],
];

foreach ($productData as $index => $product) {
    $key = "product:$index";
    $redis->hMSet($key, $product);
}

/**
 * Create a search index for locations.
 * Includes fields for a geo-point location and category.
 */
echo "Creating location index...\n";
$redis->rawCommand('FT.CREATE', 'locations', 'ON', 'HASH', 'SCHEMA',
    'name', 'TEXT',
    'category', 'TAG',
    'location', 'GEO'
);

/**
 * Add location data to Redis.
 */
echo "Adding location data...\n";
$locationData = [
    [
        'name' => 'Central Park',
        'category' => 'park',
        'location' => '-73.965355,40.782865', // Longitude,Latitude (New York)
    ],
    [
        'name' => 'Metropolitan Museum',
        'category' => 'museum',
        'location' => '-73.9630,40.7794',
    ],
    [
        'name' => 'Empire State Building',
        'category' => 'landmark',
        'location' => '-73.9857,40.7485',
    ],
    [
        'name' => 'Statue of Liberty',
        'category' => 'landmark',
        'location' => '-74.0445,40.6892',
    ],
    [
        'name' => 'Times Square',
        'category' => 'tourist',
        'location' => '-73.9851,40.7580',
    ],
];

foreach ($locationData as $index => $location) {
    $key = "location:$index";
    $redis->hMSet($key, $location);
}

/**
 * Create a hash-based Bloom Filter example.
 */
echo "Adding bloom filter data...\n";
// Add tags to a hash for products with specific tags (e.g., "gaming", "office", "electronics").
$redis->hMSet('tags:product:0', ['gaming' => true, 'electronics' => true]); // Gaming Laptop
$redis->hMSet('tags:product:1', ['office' => true, 'electronics' => true]); // Office Laptop
$redis->hMSet('tags:product:2', ['gaming' => true, 'electronics' => true]); // Gaming Monitor
$redis->hMSet('tags:product:3', ['office' => true]);                       // Wireless Keyboard

echo "Data successfully added to Redis!\n";