<?php

use Baander\RedisStack\Result\SearchResult;
use Baander\RedisStack\Search\SearchQuery;

require_once __DIR__ . '/setup-redis.php';
require_once __DIR__ . '/add-data.php'; // Include this for data setup

$redis = setupRedis();

$indexName = 'products';

// Create a new SearchQuery instance
$search = new SearchQuery($redis, $indexName);

try {
    $result = $search->query('phone')
        ->sortBy('price', 'ASC') // Sort results by price in ascending order
        ->limit(10, 5)           // Fetch 5 results starting from the 10th (pagination)
        ->execute(documentsAsArray: true);

    // Display the search results
    if ($result instanceof SearchResult) {
        echo 'Sorted and paginated results found: ' . $result->getCount() . "\n";

        foreach ($result->getDocuments() as $document) {
            print_r($document);
        }
    }
} catch (\Exception $e) {
    echo 'Sorting and pagination search failed: ' . $e->getMessage();
}