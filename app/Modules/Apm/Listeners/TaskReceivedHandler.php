<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Laravel\Octane\Events\TaskReceived;
use Psr\Log\LoggerInterface;

class TaskReceivedHandler
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle the event.
     */
    public function handle(TaskReceived $event): void
    {
        /** @var OctaneApmManager $manager */
        $manager = $event->app->make(OctaneApmManager::class);

        $taskName = $this->getTaskName($event);
        $context = $this->buildTaskContext($event);

        $transaction = $manager->beginTransaction($taskName, 'task', $context);

        if ($transaction) {
            $this->addTaskTags($manager, $event);
            $manager->addCustomContext($context);
        }
    }

    /**
     * Get task name from the event
     */
    private function getTaskName(TaskReceived $event): string
    {
        // Try to extract task name from task data
        if (isset($event->task) && is_object($event->task)) {
            $className = get_class($event->task);
            return 'Task ' . class_basename($className);
        }

        if (isset($event->task) && is_array($event->task) && isset($event->task['name'])) {
            return 'Task ' . $event->task['name'];
        }

        return 'Task';
    }

    /**
     * Build task context
     */
    private function buildTaskContext(TaskReceived $event): array
    {
        $context = [
            'task' => [
                'type'      => 'octane_task',
                'worker_id' => getmypid(),
            ],
        ];

        // Add task-specific information if available
        if (isset($event->task)) {
            if (is_object($event->task)) {
                $context['task']['class'] = get_class($event->task);
            } else if (is_array($event->task)) {
                $context['task']['data'] = $this->sanitizeTaskData($event->task);
            }
        }

        return $context;
    }

    /**
     * Sanitize task data to remove sensitive information
     */
    private function sanitizeTaskData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitizeTaskData($value);
            }
        }

        return $data;
    }

    /**
     * Add task-specific tags
     */
    private function addTaskTags(OctaneApmManager $manager, TaskReceived $event): void
    {
        $manager->addCustomTag('task.worker_id', getmypid());

        if (isset($event->task) && is_object($event->task)) {
            $manager->addCustomTag('task.class', get_class($event->task));
        }
    }
}