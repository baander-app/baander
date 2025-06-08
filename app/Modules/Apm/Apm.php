<?php

namespace App\Modules\Apm;

use Illuminate\Support\Facades\Facade;

/**
 * APM Facade for easy access to APM functionality
 *
 * @method static \Elastic\Apm\TransactionInterface|null beginTransaction(string $name, string $type, array $context = [])
 * @method static \Elastic\Apm\SpanInterface|null createSpan(string $name, string $type, string $subtype = null, string $action = null)
 * @method static void addCustomTag(string $key, $value)
 * @method static void addCustomContext(array $context)
 * @method static void recordException(\Throwable $exception, array $context = [])
 * @method static \Elastic\Apm\TransactionInterface|null getTransaction()
 * @method static void setTransactionResult(string $result = null)
 * @method static void setTransactionOutcome(string $outcome)
 * @method static void endTransaction()
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