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
    $result = $search->query('laptop')
        ->where('price', 500, 1500)          // Numeric range filter
        ->tagFilter('brand', ['Dell', 'HP']) // Tag filter for specific brands
        ->highlight(
            fields: ['title', 'description'],
            openTag: '<b>',
            closeTag: '</b>'
        )
        ->summarize(
            fields: ['description'],
            fragmentCount: 2,
            fragmentLength: 30
        )
        ->returnFields(['title', 'price', 'description']) // Only fetch specific fields
        ->limit(0, 10)                                    // Pagination (offset: 0, page size: 10)
        ->withScores()                                    // Include scores in results
        ->execute(documentsAsArray: true);

    // Display the search results
    if ($result instanceof SearchResult) {
        echo 'Total results found: ' . $result->getCount() . "\n";

        foreach ($result->getDocuments() as $document) {
            print_r($document);
        }
    }
} catch (\Exception $e) {
    echo 'Search failed: ' . $e->getMessage();
}