<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Recommendation Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for recommendations when not specified in the model config
    |
    */
    'defaults' => [
        'count' => 10,  // Default number of recommendations to return
        'order' => 'desc', // Default ordering (desc, asc, random)
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for recommendations
    |
    */
    'cache' => [
        'enabled' => true,  // Enable caching by default
        'ttl' => 3600,      // Cache TTL in seconds (1 hour)
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for batch processing of recommendations
    |
    */
    'batch' => [
        'size' => 1000,  // Default batch size for insertions
    ],

    /*
    |--------------------------------------------------------------------------
    | Algorithm Settings
    |--------------------------------------------------------------------------
    |
    | Settings for specific recommendation algorithms
    |
    */
    'algorithms' => [
        'similarity' => [
            'default_weights' => [
                'taxonomy' => 1.0,
                'feature' => 0.5,
                'numeric' => 0.5,
            ],
        ],
        'content_based' => [
            'default_content_field' => 'description',
            'stopwords' => ['the', 'and', 'a', 'to', 'of', 'in', 'is', 'that', 'it', 'with', 'for', 'as', 'on', 'at'],
        ],
    ],
];

/**
 * /**
 *  Define recommendation configurations for this model
 *
 * @return array
 * /
 * public function getRecommendationConfig(): array
 * {
 * return [
 * 'similar_products' => [
 * 'algorithm' => 'similarity',
 * 'count' => 6,
 * 'similarity_taxonomy_attributes' => [
 * ['categories' => 'name'],
 * ['tags' => 'name'],
 * ],
 * 'similarity_feature_attributes' => [
 * 'is_featured', 'is_new', 'is_on_sale'
 * ],
 * 'similarity_numeric_value_attributes' => [
 * 'price', 'rating'
 * ],
 * 'similarity_taxonomy_weight' => 1.0,
 * 'similarity_feature_weight' => 0.5,
 * 'similarity_numeric_value_weight' => 0.7,
 * 'order' => 'desc',
 * 'with' => ['media', 'categories'], // eager load these relations
 * ],
 * 'content_similar' => [
 * 'algorithm' => 'content_based',
 * 'content_field' => 'description',
 * 'count' => 4,
 * ],
 * 'frequently_bought_together' => [
 * 'algorithm' => 'db_relation',
 * 'data_table' => 'order_items',
 * 'data_field' => 'product_id',
 * 'group_field' => 'order_id',
 * 'count' => 3,
 * 'data_table_filter' => [
 * 'created_at' => ['>', now()->subMonths(6)],
 * ],
 * ],
 * ];
 * }
 */

/**
 * use App\Models\Product;
 * use App\Modules\Recommendation\Services\RecommendationService;
 *
 * class ProductController extends Controller
 * {
 * protected RecommendationService $recommendationService;
 *
 * public function __construct(RecommendationService $recommendationService)
 * {
 * $this->recommendationService = $recommendationService;
 * }
 *
 * public function show(Product $product)
 * {
 * // Get existing recommendations
 * $similarProducts = $this->recommendationService->getRecommendations($product, 'similar_products');
 * $contentSimilar = $this->recommendationService->getRecommendations($product, 'content_similar');
 * $frequentlyBoughtTogether = $this->recommendationService->getRecommendations($product, 'frequently_bought_together');
 *
 * return view('products.show', [
 * 'product' => $product,
 * 'similarProducts' => $similarProducts,
 * 'contentSimilar' => $contentSimilar,
 * 'frequentlyBoughtTogether' => $frequentlyBoughtTogether,
 * ]);
 * }
 *
 * public function generateRecommendations()
 * {
 * // Generate recommendations for all products
 * $similarCount = $this->recommendationService->generateRecommendations(Product::class, 'similar_products');
 * $contentCount = $this->recommendationService->generateRecommendations(Product::class, 'content_similar');
 * $fbTogether = $this->recommendationService->generateRecommendations(Product::class, 'frequently_bought_together');
 *
 * return response()->json([
 * 'similar_products' => $similarCount,
 * 'content_similar' => $contentCount,
 * 'frequently_bought_together' => $fbTogether,
 * ]);
 * }
 *
 * public function refreshRecommendations(Product $product)
 * {
 * // Generate recommendations for a specific product
 * $similarCount = $this->recommendationService->generateRecommendationsForModel($product, 'similar_products');
 * $contentCount = $this->recommendationService->generateRecommendationsForModel($product, 'content_similar');
 * $fbTogether = $this->recommendationService->generateRecommendationsForModel($product, 'frequently_bought_together');
 *
 * return response()->json([
 * 'similar_products' => $similarCount,
 * 'content_similar' => $contentCount,
 * 'frequently_bought_together' => $fbTogether,
 * ]);
 * }
 * }
 */