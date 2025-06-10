<?php

namespace App\Modules\Apm;

use BadMethodCallException;
use Elastic\Apm\ElasticApm;
use Elastic\Apm\ExecutionSegmentInterface;
use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

class OctaneApmManager
{
    /**
     * Dictates if APM is disabled
     */
    private bool $disabled;

    /**
     * Dictates if the current transaction is being sampled
     */
    private bool $sampled;

    /**
     * The main outer transaction wrapping all child spans
     */
    private ?TransactionInterface $transaction = null;

    /**
     * Holds all stored spans indexed by their name
     */
    private array $spans = [];

    /**
     * APM configuration
     */
    private array $config;

    /**
     * Logger instance
     */
    private ?LoggerInterface $logger;

    /**
     * Custom context data
     */
    private array $customContext = [];

    /**
     * Transaction start time for performance tracking
     */
    private ?float $transactionStartTime = null;

    /**
     * Transaction metadata
     */
    private array $transactionMetadata = [];

    /**
     * Maximum number of spans per transaction
     */
    private int $maxSpans;

    /**
     * Current span count
     */
    private int $spanCount = 0;

    /**
     * Constructor
     */
    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->disabled = !class_exists(ElasticApm::class);
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->sampled = $this->shouldSample();
        $this->maxSpans = $this->config['transaction']['max_spans'] ?? 500;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled'         => true,
            'sampling_rate'   => 1.0,
            'service_name'    => 'baander',
            'service_version' => '1.0.0',
            'environment'     => 'unknown',
            'transaction'     => [
                'max_spans'         => 500,
                'stack_trace_limit' => 50,
            ],
            'context'         => [
                'sanitize_field_names' => [
                    'password',
                    'token',
                    'secret',
                    'key',
                    'auth',
                    'authorization',
                    '_token',
                    '_secret',
                    '_key',
                    '_auth',
                    '_authorization',
                ],
            ],
            'ignore_patterns' => [
                'routes'      => [],
                'user_agents' => [],
            ],
            'monitoring'      => [
                'database'    => true,
                'cache'       => true,
                'http_client' => true,
            ],
        ];
    }

    /**
     * Check if sampling should occur
     */
    private function shouldSample(): bool
    {
        if ($this->disabled) {
            return false;
        }

        $samplingRate = $this->config['sampling_rate'] ?? 1.0;

        // Ensure the sampling rate is between 0 and 1
        $samplingRate = max(0.0, min(1.0, (float)$samplingRate));

        return mt_rand(1, 10000) / 10000 <= $samplingRate;
    }

    /**
     * Begins a new transaction
     */
    public function beginTransaction(string $name, string $type, array $context = []): ?TransactionInterface
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($this->shouldIgnoreTransaction($name, $context)) {
            return null;
        }

        try {
            $this->prepareForNextTransaction();
            $this->transactionStartTime = microtime(true);
            $this->transaction = ElasticApm::beginCurrentTransaction($name, $type);

            $this->setTransactionLimits();
            $this->addCustomContext($context);

            // Store transaction metadata
            $this->transactionMetadata = [
                'name'       => $name,
                'type'       => $type,
                'started_at' => $this->transactionStartTime,
            ];

            $this->spanCount = 0;

            return $this->transaction;
        } catch (Throwable $e) {
            $this->logError('Failed to begin transaction', $e, compact('name', 'type'));
            return null;
        }
    }

    /**
     * Check if APM is available and enabled
     */
    public function isEnabled(): bool
    {
        return !$this->disabled && $this->sampled && ($this->config['enabled'] ?? true);
    }

    /**
     * Check if a transaction should be ignored based on patterns
     */
    public function shouldIgnoreTransaction(string $transactionName, array $context = []): bool
    {
        if (!$this->config['enabled'] ?? true) {
            return true;
        }

        // Check route patterns
        $ignoreRoutes = $this->config['ignore_patterns']['routes'] ?? [];
        if (array_any($ignoreRoutes, fn($pattern) => fnmatch($pattern, $transactionName))) {
            return true;
        }

        // Check user agent patterns
        $userAgent = $context['request']['user_agent'] ?? '';
        if ($userAgent) {
            $ignoreUserAgents = $this->config['ignore_patterns']['user_agents'] ?? [];
            if (array_any($ignoreUserAgents, fn($pattern) => fnmatch($pattern, $userAgent))) {
                return true;
            }
        }

        // Check if transaction is health check or monitoring
        $healthCheckPatterns = ['*/health', '*/ping', '*/status', '*/metrics'];
        return array_any($healthCheckPatterns, fn($pattern) => fnmatch($pattern, $transactionName));

    }

    /**
     * Prepares the manager and APM for the next request
     */
    private function prepareForNextTransaction(): void
    {
        $this->discardActiveSegments();
        $this->resetManager();
    }

    /**
     * Discards all currently active APM segments
     */
    private function discardActiveSegments(): void
    {
        try {
            // Discard current segments safely
            $currentTransaction = ElasticApm::getCurrentTransaction();
            if (!$currentTransaction->hasEnded()) {
                $this->discardSegment($currentTransaction);
            }

            $currentSegment = ElasticApm::getCurrentExecutionSegment();
            if (!$currentSegment->hasEnded()) {
                $this->discardSegment($currentSegment);
            }

            if ($this->transaction && !$this->transaction->hasEnded()) {
                $this->discardSegment($this->transaction);
            }

            foreach ($this->spans as $span) {
                if (!$span->hasEnded()) {
                    $this->discardSegment($span);
                }
            }
        } catch (Throwable $e) {
            $this->logError('Failed to discard active segments', $e);
        }
    }

    /**
     * Discards the given execution segment
     */
    private function discardSegment(?ExecutionSegmentInterface $segment): void
    {
        if (!$segment || $segment->hasEnded()) {
            return;
        }

        try {
            $segment->discard();
        } catch (Throwable $e) {
            $this->logError('Failed to discard segment', $e);
        }
    }

    /**
     * Log an error with context
     */
    private function logError(string $message, Throwable $e, array $context = []): void
    {
        $this->logger?->error($message, [
            'exception' => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'context'   => $context,
            'apm_state' => $this->getTransactionStats(),
        ]);
    }

    /**
     * Get transaction statistics
     */
    public function getTransactionStats(): array
    {
        return [
            'has_transaction'      => !$this->hasNoTransactionInstance(),
            'span_count'           => $this->spanCount,
            'max_spans'            => $this->maxSpans,
            'stored_spans'         => count($this->spans),
            'transaction_metadata' => $this->transactionMetadata,
            'custom_context_keys'  => array_keys($this->customContext),
            'sampled'              => $this->sampled,
            'enabled'              => $this->isEnabled(),
        ];
    }

    /**
     * Returns true if there exists a transaction instance within the manager
     */
    public function hasNoTransactionInstance(): bool
    {
        return !$this->isEnabled() || !$this->transaction;
    }

    /**
     * Resets the manager state
     */
    private function resetManager(): void
    {
        $this->transaction = null;
        $this->transactionStartTime = null;
        $this->spans = [];
        $this->customContext = [];
        $this->transactionMetadata = [];
        $this->spanCount = 0;
    }

    /**
     * Set transaction limits from configuration
     */
    private function setTransactionLimits(): void
    {
        if (!$this->transaction) {
            return;
        }

        try {
            // Note: setMaxNumberOfSpans and setStackTraceLimit don't exist on transactions
            // These would typically be configured at the agent level via configuration
            // We can add them as labels for monitoring purposes
            if (isset($this->config['transaction']['max_spans'])) {
                $this->transaction->context()->setLabel('config.max_spans', (string)$this->config['transaction']['max_spans']);
            }

            if (isset($this->config['transaction']['stack_trace_limit'])) {
                $this->transaction->context()->setLabel('config.stack_trace_limit', (string)$this->config['transaction']['stack_trace_limit']);
            }
        } catch (Throwable $e) {
            $this->logError('Failed to set transaction limits', $e);
        }
    }

    /**
     * Add custom context to the current transaction
     */
    public function addCustomContext(array $context): void
    {
        $this->customContext = array_merge_recursive($this->customContext, $context);

        if (!$this->isEnabled() || !$this->transaction) {
            return;
        }

        try {
            $sanitizedContext = $this->sanitizeContext($context);

            // Use labels for custom data since setCustomContext/setUserContext don't exist
            foreach ($sanitizedContext as $key => $value) {
                if (is_array($value)) {
                    // Flatten arrays into labels with dot notation
                    $this->setNestedTransactionLabels($key, $value);
                } else {
                    $this->transaction->context()->setLabel($key, $this->sanitizeTagValue($value));
                }
            }
        } catch (Throwable $e) {
            $this->logError('Failed to add custom context', $e, ['context_keys' => array_keys($context)]);
        }
    }

    /**
     * Sanitize context data to remove sensitive information
     */
    private function sanitizeContext(array $context): array
    {
        $sanitizeFields = $this->config['context']['sanitize_field_names'] ?? [];
        $maxDepth = 10; // Prevent infinite recursion

        return $this->recursiveSanitize($context, $sanitizeFields, 0, $maxDepth);
    }

    /**
     * Recursively sanitize an array
     */
    private function recursiveSanitize(array $data, array $sensitiveFields, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return ['[MAX_DEPTH_REACHED]'];
        }

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            // Check if a key contains sensitive information
            $isSensitive = false;
            foreach ($sensitiveFields as $sensitiveField) {
                if (str_contains($lowerKey, strtolower($sensitiveField))) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $data[$key] = '[REDACTED]';
            } else if (is_array($value)) {
                $data[$key] = $this->recursiveSanitize($value, $sensitiveFields, $depth + 1, $maxDepth);
            } else if (is_object($value)) {
                $data[$key] = '[OBJECT:' . get_class($value) . ']';
            } else if (is_resource($value)) {
                $data[$key] = '[RESOURCE:' . get_resource_type($value) . ']';
            } else if (is_string($value) && strlen($value) > 10000) {
                // Truncate very long strings
                $data[$key] = substr($value, 0, 10000) . '... [TRUNCATED]';
            }
        }

        return $data;
    }

    /**
     * Set nested labels on transaction
     */
    private function setNestedTransactionLabels(string $prefix, array $data, int $depth = 0): void
    {
        if ($depth > 3) { // Limit nesting depth
            return;
        }

        foreach ($data as $key => $value) {
            $labelKey = $prefix . '.' . $key;

            if (is_array($value)) {
                $this->setNestedTransactionLabels($labelKey, $value, $depth + 1);
            } else {
                $this->transaction->context()->setLabel($labelKey, $this->sanitizeTagValue($value));
            }
        }
    }

    /**
     * Sanitize tag value
     */
    private function sanitizeTagValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value) || is_object($value)) {
            return '[COMPLEX_VALUE]';
        }

        $stringValue = (string)$value;

        // Limit tag value length
        if (strlen($stringValue) > 1024) {
            return substr($stringValue, 0, 1024) . '...';
        }

        return $stringValue;
    }

    /**
     * Begins a new span and stores it for later retrieval
     */
    public function beginAndStoreSpan(string $name, string $type): ?SpanInterface
    {
        if (!$this->isEnabled() || $this->hasNoTransactionInstance()) {
            return null;
        }

        if (isset($this->spans[$name])) {
            throw new InvalidArgumentException('Nested stored spans with the same name is not supported');
        }

        if ($this->spanCount >= $this->maxSpans) {
            $this->logger?->warning('Maximum span limit reached, dropping span', [
                'span_name'     => $name,
                'max_spans'     => $this->maxSpans,
                'current_count' => $this->spanCount,
            ]);
            return null;
        }

        try {
            $span = $this->transaction->beginChildSpan($name, $type);
            $this->spans[$name] = $span;
            $this->spanCount++;
            return $span;
        } catch (Throwable $e) {
            $this->logError('Failed to begin and store span', $e, compact('name', 'type'));
            return null;
        }
    }

    /**
     * Create a new span (not stored)
     */
    public function createSpan(string $name, string $type, ?string $subtype = null, ?string $action = null): ?SpanInterface
    {
        if (!$this->isEnabled() || $this->hasNoTransactionInstance()) {
            return null;
        }

        if ($this->spanCount >= $this->maxSpans) {
            return null;
        }

        try {
            $span = $this->transaction->beginChildSpan($name, $type, $subtype, $action);
            $this->spanCount++;

            return $span;
        } catch (Throwable $e) {
            $this->logError('Failed to create span', $e, compact('name', 'type', 'subtype', 'action'));
            return null;
        }
    }

    /**
     * Add a custom tag to the current transaction
     */
    public function addCustomTag(string $key, $value): void
    {
        if (!$this->isEnabled() || !$this->transaction) {
            return;
        }

        try {
            // Sanitize and validate the tag value
            $sanitizedValue = $this->sanitizeTagValue($value);
            // Use the correct Elastic APM method via context
            $this->transaction->context()->setLabel($key, $sanitizedValue);
        } catch (Throwable $e) {
            $this->logError('Failed to add custom tag', $e, compact('key', 'value'));
        }
    }

    /**
     * Set context on a span
     */
    public function setSpanContext(SpanInterface $span, array $context): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $sanitizedContext = $this->sanitizeContext($context);

            // Add context as labels on the span via context
            foreach ($sanitizedContext as $key => $value) {
                if (is_array($value)) {
                    // For nested arrays, flatten them
                    $this->setNestedSpanLabels($span, $key, $value);
                } else {
                    $span->context()->setLabel($key, $this->sanitizeTagValue($value));
                }
            }
        } catch (Throwable $e) {
            $this->logError('Failed to set span context', $e, ['context_keys' => array_keys($context)]);
        }
    }

    /**
     * Set nested labels on span
     */
    private function setNestedSpanLabels(SpanInterface $span, string $prefix, array $data, int $depth = 0): void
    {
        if ($depth > 3) { // Limit nesting depth
            return;
        }

        foreach ($data as $key => $value) {
            $labelKey = $prefix . '.' . $key;

            if (is_array($value)) {
                $this->setNestedSpanLabels($span, $labelKey, $value, $depth + 1);
            } else {
                $span->context()->setLabel($labelKey, $this->sanitizeTagValue($value));
            }
        }
    }

    /**
     * Add tag to span
     */
    public function addSpanTag(SpanInterface $span, string $key, $value): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $span->context()->setLabel($key, $this->sanitizeTagValue($value));
        } catch (Throwable $e) {
            $this->logError('Failed to add span tag', $e, compact('key', 'value'));
        }
    }

    /**
     * Record an exception in the current transaction
     */
    public function recordException(Throwable $exception, array $context = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $currentSegment = ElasticApm::getCurrentExecutionSegment();
            if (!$currentSegment->hasEnded()) {
                // Use the correct method from the API
                $errorId = $currentSegment->createErrorFromThrowable($exception);

                // Add context as labels if the error was created
                if ($errorId && !empty($context)) {
                    $sanitizedContext = $this->sanitizeContext($context);
                    foreach ($sanitizedContext as $key => $value) {
                        if (!is_array($value)) {
                            $currentSegment->context()->setLabel('error.' . $key, $this->sanitizeTagValue($value));
                        }
                    }
                }

                // Add transaction metadata as labels
                if ($errorId && !empty($this->transactionMetadata)) {
                    foreach ($this->transactionMetadata as $key => $value) {
                        if (!is_array($value)) {
                            $currentSegment->context()->setLabel('transaction.' . $key, $this->sanitizeTagValue($value));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logError('Failed to record exception', $e, [
                'original_exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Set the result of the transaction
     */
    public function setTransactionResult(?string $result): void
    {
        if ($transaction = $this->getTransaction()) {
            try {
                $transaction->setResult($result);
            } catch (Throwable $e) {
                $this->logError('Failed to set transaction result', $e, compact('result'));
            }
        }
    }

    /**
     * Returns the current transaction
     */
    public function getTransaction(): ?TransactionInterface
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return $this->transaction;
    }

    /**
     * Set the outcome of the transaction
     */
    public function setTransactionOutcome(string $outcome): void
    {
        if (!$this->isEnabled() || !$this->transaction) {
            return;
        }

        try {
            $this->transaction->setOutcome($outcome);
        } catch (Throwable $e) {
            $this->logError('Failed to set transaction outcome', $e, compact('outcome'));
        }
    }

    /**
     * Ends the transaction
     */
    public function endTransaction(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($this->hasNoTransactionInstance()) {
            throw new BadMethodCallException('Cannot end transaction before it has been started');
        }

        try {
            // End all remaining spans
            foreach (array_keys($this->spans) as $spanKey) {
                $this->endStoredSpan($spanKey);
            }

            // Add performance metrics
            $this->addPerformanceMetrics();

            // Add final transaction metadata
            $this->addTransactionMetadata();

            $this->endSegment($this->transaction);
            $this->resetManager();
        } catch (Throwable $e) {
            $this->logError('Failed to end transaction', $e);
            $this->resetManager();
        }
    }

    /**
     * Ends a stored span
     */
    public function endStoredSpan(string $name): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!isset($this->spans[$name])) {
            throw new InvalidArgumentException(sprintf('No stored span with name [%s] exists', $name));
        }

        try {
            $this->endSegment($this->spans[$name]);
            unset($this->spans[$name]);
        } catch (Throwable $e) {
            $this->logError('Failed to end stored span', $e, compact('name'));
        }
    }

    /**
     * Ends the given execution segment
     */
    private function endSegment(?ExecutionSegmentInterface $segment): void
    {
        if (!$segment || $segment->hasEnded()) {
            return;
        }

        try {
            $segment->end();
        } catch (Throwable $e) {
            $this->logError('Failed to end segment', $e);
        }
    }

    /**
     * Add performance metrics to the transaction
     */
    private function addPerformanceMetrics(): void
    {
        if (!$this->transaction || !$this->transactionStartTime) {
            return;
        }

        try {
            $duration = (microtime(true) - $this->transactionStartTime) * 1000; // Convert to milliseconds

            // Add performance metrics as labels
            $this->transaction->context()->setLabel('performance.duration_ms', (string)round($duration, 2));
            $this->transaction->context()->setLabel('performance.memory_peak_mb', (string)round(memory_get_peak_usage(true) / 1024 / 1024, 2));
            $this->transaction->context()->setLabel('performance.memory_current_mb', (string)round(memory_get_usage(true) / 1024 / 1024, 2));
            $this->transaction->context()->setLabel('performance.span_count', (string)$this->spanCount);
        } catch (Throwable $e) {
            $this->logError('Failed to add performance metrics', $e);
        }
    }

    /**
     * Add transaction metadata
     */
    private function addTransactionMetadata(): void
    {
        if (!$this->transaction || empty($this->transactionMetadata)) {
            return;
        }

        try {
            $this->transactionMetadata['ended_at'] = microtime(true);
            $this->transactionMetadata['duration_seconds'] = $this->transactionMetadata['ended_at'] - $this->transactionMetadata['started_at'];

            // Add metadata as labels
            foreach ($this->transactionMetadata as $key => $value) {
                if (!is_array($value)) {
                    $this->transaction->context()->setLabel('metadata.' . $key, $this->sanitizeTagValue($value));
                }
            }
        } catch (Throwable $e) {
            $this->logError('Failed to add transaction metadata', $e);
        }
    }
}