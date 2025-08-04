<?php

use Baander\RedisStack\Result\SearchResult;
use Baander\RedisStack\Search\SearchQuery;

require_once __DIR__ . '/setup-redis.php';
require_once __DIR__ . '/add-data.php'; // Include this for data setup

$redis = setupRedis();

$indexName = 'products';

// Create a new SearchQuery instance
$search = new SearchQuery($redis, $indexName);

// Perform a basic search with default parameters
try {
    $result = $search->query('laptop')
        ->execute(documentsAsArray: true);

    // Display the search results
    if ($result instanceof SearchResult) {
        echo 'Total results found: ' . $result->getCount() . "\n";

        foreach ($result->getDocuments() as $document) {
            print_r($document);
        }
    }
} catch (Exception $e) {
    echo 'Search failed: ' . $e->getMessage();
}