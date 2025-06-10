<?php

namespace App\Modules\Apm;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static \Elastic\Apm\TransactionInterface|null beginTransaction(string $name, string $type, array $context = [])
 * @method static \Elastic\Apm\SpanInterface|null createSpan(string $name, string $type, string $subtype = null, string $action = null)
 * @method static \Elastic\Apm\SpanInterface|null beginAndStoreSpan(string $name, string $type)
 * @method static void endStoredSpan(string $name)
 * @method static void recordException(\Throwable $exception, array $context = [])
 * @method static void addCustomContext(array $context)
 * @method static void addCustomTag(string $key, mixed $value)
 * @method static array getTransactionStats()
 *
 * @see OctaneApmManager
 */
class Apm extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return OctaneApmManager::class;
    }
}