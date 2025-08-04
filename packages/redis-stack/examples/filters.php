<?php

use Baander\RedisStack\Result\SearchResult;
use Baander\RedisStack\Search\SearchQuery;

require_once __DIR__ . '/setup-redis.php';
require_once __DIR__ . '/add-data.php'; // Include this for data setup

$redis = setupRedis();

$indexName = 'locations';

// Create a new SearchQuery instance
$search = new SearchQuery($redis, $indexName);

try {
    $result = $search->query('*')
        ->geoFilter('location', 40.7128, -74.0060, 10.0, 'mi') // Geo filter for locations within 10 miles
        ->tagFilter('category', ['restaurant', 'shop'])         // Filter by categories
        ->bloomFilter('tags', 'food')                          // Bloom filter to match tags
        ->limit(0, 5)                                          // Limit results to the first 5
        ->execute(documentsAsArray: true);

    // Display the search results
    if ($result instanceof SearchResult) {
        echo 'Filtered results found: ' . $result->getCount() . "\n";

        foreach ($result->getDocuments() as $document) {
            print_r($document);
        }
    }
} catch (Exception $e) {
    echo 'Filter-based search failed: ' . $e->getMessage();
}