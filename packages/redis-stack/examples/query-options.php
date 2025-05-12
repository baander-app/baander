<?php

use Baander\RedisStack\Search\SearchQuery;

require_once __DIR__ . '/setup-redis.php';
require_once __DIR__ . '/add-data.php'; // Include this for data setup

$redis = setupRedis();

$indexName = 'products';

// Search with advanced query options
$search = new SearchQuery($redis, $indexName);

try {
    $search->query('gaming')
        ->setLanguage('english')      // Specify language for stemming
        ->setScorer('tfidf')          // Use TF-IDF as the scoring function
        ->setExpander('SYNONYM')      // Expand query with synonyms
        ->setPayload('special-query') // Associate a payload with this query
        ->execute();
} catch (\Exception $e) {
    echo 'Query options search failed: ' . $e->getMessage();
}