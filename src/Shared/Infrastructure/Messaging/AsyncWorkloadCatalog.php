<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

/** The admitted asynchronous workloads and their shared transport policies. */
final readonly class AsyncWorkloadCatalog
{
    /** @var array<class-string, array{transport: string, resource_class: string, default: bool}> */
    private array $workloads;

    /** @var array<string, array{max_retries: int, delay: int, multiplier: int|float, max_delay: int}> */
    private array $transportPolicies;

    /**
     * @param array<array-key, mixed> $workloads Unvalidated configuration rows.
     * @param array<array-key, mixed> $transportPolicies Unvalidated configuration policies.
     */
    public function __construct(array $workloads, array $transportPolicies)
    {
        if (array_keys($transportPolicies) !== ['async', 'scheduler']) {
            throw new \LogicException('Asynchronous transport policies must declare async and scheduler in receiver order.');
        }
        $validatedPolicies = [];
        foreach ($transportPolicies as $transport => $policy) {
            if (!is_array($policy) || count($policy) !== 4
                || !is_int($policy['max_retries'] ?? null) || $policy['max_retries'] < 0
                || !is_int($policy['delay'] ?? null) || $policy['delay'] < 0
                || (!is_int($policy['multiplier'] ?? null) && !is_float($policy['multiplier'] ?? null))
                || !is_finite((float) $policy['multiplier']) || $policy['multiplier'] < 1
                || !is_int($policy['max_delay'] ?? null) || $policy['max_delay'] <= 0
                || $policy['max_delay'] < $policy['delay']) {
                throw new \LogicException(sprintf('Transport "%s" requires a bounded retry policy.', $transport));
            }
            $validatedPolicies[$transport] = [
                'max_retries' => $policy['max_retries'], 'delay' => $policy['delay'],
                'multiplier' => $policy['multiplier'], 'max_delay' => $policy['max_delay'],
            ];
        }
        $validatedWorkloads = [];
        foreach ($workloads as $class => $workload) {
            if (!is_string($class) || !class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                throw new \LogicException(sprintf('Workload "%s" must name a concrete message class.', $class));
            }
            if (!is_array($workload) || count($workload) !== 3
                || !is_string($workload['transport'] ?? null)
                || !isset($transportPolicies[$workload['transport']])
                || !in_array($workload['resource_class'] ?? null, ['io', 'cpu', 'coordination'], true)
                || !is_bool($workload['default'] ?? null)) {
                throw new \LogicException(sprintf('Workload "%s" requires one permitted transport, resource class and routing mode.', $class));
            }
            $validatedWorkloads[$class] = [
                'transport' => $workload['transport'], 'resource_class' => $workload['resource_class'],
                'default' => $workload['default'],
            ];
        }
        $this->workloads = $validatedWorkloads;
        $this->transportPolicies = $validatedPolicies;
    }

    /** @return array<class-string, array{transport: string, resource_class: string, default: bool}> */
    public function workloads(): array
    {
        return $this->workloads;
    }

    /** @return array<string, array{max_retries: int, delay: int, multiplier: int|float, max_delay: int}> */
    public function transportPolicies(): array
    {
        return $this->transportPolicies;
    }

    /** @return list<string> */
    public function receivers(): array
    {
        return array_keys($this->transportPolicies);
    }

    /** @param list<string> $senderAliases */
    public function assertSenders(object $message, array $senderAliases): void
    {
        $workload = $this->workloads[$message::class] ?? null;
        if ($senderAliases === [] && ($workload === null || !$workload['default'])) {
            return;
        }
        if ($workload === null || $senderAliases !== [$workload['transport']]) {
            throw new \LogicException(sprintf('Message "%s" must select its single admitted asynchronous transport; selected [%s].', $message::class, implode(', ', $senderAliases)));
        }
    }
}
