<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Psr\Log\LoggerInterface;
use Throwable;

class DefaultTerminatedHandler
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        /** @var OctaneApmManager $manager */
        $manager = $event->app->make(OctaneApmManager::class);

        try {
            $this->setTransactionResult($manager, $event);
            $this->setTransactionOutcome($manager, $event);
            $this->addEventContext($manager, $event);
            $this->addTags($manager, $event);

            $manager->endTransaction();
        } catch (Throwable $e) {
            $this->logger?->error('Error during transaction termination', [
                'exception'  => $e->getMessage(),
                'event_type' => get_class($event),
            ]);

            // Ensure transaction always ends, even if there's an error
            try {
                $manager->endTransaction();
            } catch (Throwable $secondaryError) {
                $this->logger?->error('Secondary error during transaction cleanup', [
                    'exception' => $secondaryError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Set the transaction result based on event type
     */
    private function setTransactionResult(OctaneApmManager $manager, object $event): void
    {
        $result = match (true) {
            $event instanceof RequestTerminated => $this->getHttpResult($event),
            $event instanceof TaskTerminated => $this->getTaskResult($event),
            $event instanceof TickTerminated => 'success',
            default => 'unknown'
        };

        $manager->setTransactionResult($result);
    }

    /**
     * Get HTTP result string
     */
    private function getHttpResult(RequestTerminated $event): string
    {
        $code = (string)$event->response->getStatusCode();
        return 'HTTP ' . $code[0] . str_repeat('x', strlen($code) - 1);
    }

    /**
     * Get task result
     */
    private function getTaskResult(TaskTerminated $event): string
    {
        return ($event->exitCode ?? 0) === 0 ? 'success' : 'failure';
    }

    /**
     * Set the transaction outcome
     */
    private function setTransactionOutcome(OctaneApmManager $manager, object $event): void
    {
        $outcome = match (true) {
            $event instanceof RequestTerminated => $this->getHttpOutcome($event),
            $event instanceof TaskTerminated => $this->getTaskOutcome($event),
            $event instanceof TickTerminated => 'success',
            default => 'unknown'
        };

        $manager->setTransactionOutcome($outcome);
    }

    /**
     * Get HTTP outcome
     */
    private function getHttpOutcome(RequestTerminated $event): string
    {
        $statusCode = $event->response->getStatusCode();

        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 400 && $statusCode < 500, $statusCode >= 500 => 'failure',
            default => 'unknown'
        };
    }

    /**
     * Get task outcome
     */
    private function getTaskOutcome(TaskTerminated $event): string
    {
        return ($event->exitCode ?? 0) === 0 ? 'success' : 'failure';
    }

    /**
     * Add event-specific context
     */
    private function addEventContext(OctaneApmManager $manager, object $event): void
    {
        $transaction = $manager->getTransaction();
        if (!$transaction) {
            return;
        }

        if ($event instanceof RequestTerminated) {
            $this->addRequestTerminatedContext($manager, $event);
        } else if ($event instanceof TaskTerminated) {
            $this->addTaskTerminatedContext($manager, $event);
        } else if ($event instanceof TickTerminated) {
            $this->addTickTerminatedContext($manager, $event);
        }
    }

    /**
     * Add context for request terminated events
     */
    private function addRequestTerminatedContext(OctaneApmManager $manager, RequestTerminated $event): void
    {
        $response = $event->response;
        $content = $response->getContent();

        $context = [
            'response' => [
                'status_code'    => $response->getStatusCode(),
                'headers'        => $this->getFilteredResponseHeaders($response),
                'content_length' => strlen($content),
                'content_type'   => $response->headers->get('Content-Type'),
            ],
        ];

        // Add response body for errors if configured
        if ($response->getStatusCode() >= 400) {
            $captureBody = config('apm.transaction.capture_body', 'errors');
            if ($captureBody === 'all' || $captureBody === 'errors') {
                $context['response']['body'] = $this->sanitizeResponseBody($content);
            }
        }

        $manager->addCustomContext($context);
    }

    /**
     * Get filtered response headers
     */
    private function getFilteredResponseHeaders($response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $lowerName = strtolower($name);
            if (!in_array($lowerName, ['set-cookie', 'authorization'])) {
                $headers[$name] = $values;
            }
        }
        return $headers;
    }

    /**
     * Sanitize response body
     */
    private function sanitizeResponseBody(string $content): string
    {
        // Truncate large responses
        if (strlen($content) > 2048) {
            $content = substr($content, 0, 2048) . '... [TRUNCATED]';
        }

        // If it's JSON, try to decode and remove sensitive fields
        if ($this->isJson($content)) {
            try {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $data = $this->sanitizeArray($data);
                    return json_encode($data);
                }
            } catch (Throwable) {
                // If JSON parsing fails, return original content
            }
        }

        return $content;
    }

    /**
     * Check if string is JSON
     */
    private function isJson(string $string): bool
    {
        return json_validate($string);
    }

    /**
     * Sanitize array by removing sensitive fields
     */
    private function sanitizeArray(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'auth'];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            $isSensitive = false;
            foreach ($sensitiveFields as $sensitiveField) {
                if (str_contains($lowerKey, $sensitiveField)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $data[$key] = '[REDACTED]';
            } else if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            }
        }

        return $data;
    }

    /**
     * Add context for task terminated events
     */
    private function addTaskTerminatedContext(OctaneApmManager $manager, TaskTerminated $event): void
    {
        $context = [
            'task' => [
                'exit_code'   => $event->exitCode ?? null,
                'duration_ms' => isset($event->duration) ? round($event->duration * 1000, 2) : null,
            ],
        ];

        if (isset($event->exception)) {
            $manager->recordException($event->exception, $context);
        }

        $manager->addCustomContext($context);
    }

    /**
     * Add context for tick terminated events
     */
    private function addTickTerminatedContext(OctaneApmManager $manager, TickTerminated $event): void
    {
        $context = [
            'tick' => [
                'duration_ms' => isset($event->duration) ? round($event->duration * 1000, 2) : null,
            ],
        ];

        $manager->addCustomContext($context);
    }

    /**
     * Add tags based on event type
     */
    private function addTags(OctaneApmManager $manager, object $event): void
    {
        if ($event instanceof RequestTerminated) {
            $manager->addCustomTag('response.status_code', $event->response->getStatusCode());
            $manager->addCustomTag('response.status_class', $this->getStatusClass($event->response->getStatusCode()));
        } else if ($event instanceof TaskTerminated) {
            $manager->addCustomTag('task.exit_code', $event->exitCode ?? 'unknown');
        }
    }

    /**
     * Get status class for status code
     */
    private function getStatusClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => '2xx',
            $statusCode >= 300 && $statusCode < 400 => '3xx',
            $statusCode >= 400 && $statusCode < 500 => '4xx',
            $statusCode >= 500 => '5xx',
            default => 'unknown'
        };
    }
}