<?php

namespace Baander\RedisStack;

use Redis;

class RuntimeConfiguration
{
    public function __construct(
        private readonly Redis $redis
    )
    {
    }

    /**
     * @param string $command
     * @param array $arguments
     * @return mixed
     */
    protected function rawCommand(string $command, array $arguments)
    {
        return $this->redis->rawCommand($command, $arguments);
    }

    public function getOption(string $name): string|int|bool
    {
        $response = $this->rawCommand('FT.CONFIG', ['GET', $name]);
        return is_numeric($response) ? intval($response) : $response;
    }

    public function setOption(string $name, string|int|bool $value): bool
    {
        return $this->rawCommand('FT.CONFIG', ['SET', $name, $value]) === 'OK';
    }

    public function setSearchTimeout(int $milliseconds = 500): bool
    {
        $this->setTimeoutInMilliseconds($milliseconds);
        return $this->setOnTimeoutPolicyToReturn();
    }

    public function getMaxSearchResults(): int
    {
        return $this->convertRawResponseToInt($this->getOption('MAXSEARCHRESULTS'));
    }

    public function setMaxSearchResults(int $value): void
    {
        $this->setOption('MAXSEARCHRESULTS', $value);
    }

    public function optimizeForLargeIndexes(int $maxDocTableSize = 1000000, int $threadPoolSize = 4): void
    {
        $this->setOption('MAXDOCTABLESIZE', $maxDocTableSize);
        $this->setOption('THREAD_POOL_SIZE', $threadPoolSize);
    }

    public function getStats(string $indexName): array
    {
        return $this->rawCommand('FT.INFO', [$indexName]);
    }

    public function resetOptionsToDefault(): void
    {
        // Reset to RedisSearch defaults
        $this->setMinPrefix();
        $this->setMaxExpansions();
        $this->setTimeoutInMilliseconds();
        $this->setOnTimeoutPolicyToReturn();
        $this->setMinPhoneticTermLength();
    }

    public function getForkGCCleanThreshold(): int
    {
        return $this->convertRawResponseToInt($this->getOption('FORK_GC_CLEAN_THRESHOLD'));
    }

    public function setForkGCCleanThreshold(int $value): void
    {
        $this->setOption('FORK_GC_CLEAN_THRESHOLD', $value);
    }

    public function exportConfiguration(): array
    {
        return [
            'MINPREFIX' => $this->getMinPrefix(),
            'MAXEXPANSIONS' => $this->getMaxExpansions(),
            'TIMEOUT' => $this->getTimeoutInMilliseconds(),
            'ON_TIMEOUT' => $this->isOnTimeoutPolicyReturn() ? 'return' : 'fail',
            'MIN_PHONETIC_TERM_LEN' => $this->getMinPhoneticTermLength(),
        ];
    }

    public function getDefaultDialect(): int
    {
        return $this->convertRawResponseToInt($this->getOption('DEFAULT_DIALECT'));
    }

    public function setDefaultDialect(int $value): void
    {
        $this->setOption('DEFAULT_DIALECT', $value);
    }

    public function setQueryDialect(int $dialectId): bool
    {
        return $this->setOption('DEFAULT_DIALECT', $dialectId);
    }

    protected function convertRawResponseToString(array $rawResponse): string
    {
        $value = $rawResponse[0][1];
        if (is_object($value) && method_exists($value, 'getPayload')) {
            $value = $value->getPayload();
        }
        return $value;
    }

    protected function convertRawResponseToInt($rawResponse): int
    {
        return intval($this->convertRawResponseToString($rawResponse));
    }

    public function getMinPrefix(): int
    {
        return $this->convertRawResponseToInt($this->getOption('MINPREFIX'));
    }

    public function setMinPrefix(int $value = 2)
    {
        return $this->setOption('MINPREFIX', $value);
    }

    public function getMaxExpansions(): int
    {
        return $this->convertRawResponseToInt($this->getOption('MAXEXPANSIONS'));
    }

    public function setMaxExpansions(int $value = 200): bool
    {
        return $this->setOption('MAXEXPANSIONS', $value);
    }

    public function getTimeoutInMilliseconds(): int
    {
        return $this->convertRawResponseToInt($this->getOption('TIMEOUT'));
    }

    public function setTimeoutInMilliseconds(int $value = 500)
    {
        return $this->setOption('TIMEOUT', $value);
    }

    public function isOnTimeoutPolicyReturn(): bool
    {
        return $this->convertRawResponseToString($this->getOption('ON_TIMEOUT')) === 'return';
    }

    public function isOnTimeoutPolicyFail(): bool
    {
        return $this->convertRawResponseToString($this->getOption('ON_TIMEOUT')) === 'fail';
    }

    public function setOnTimeoutPolicyToReturn(): bool
    {
        return $this->setOption('ON_TIMEOUT', 'return');
    }

    public function setOnTimeoutPolicyToFail(): bool
    {
        return $this->setOption('ON_TIMEOUT', 'fail');
    }

    public function getMinPhoneticTermLength(): int
    {
        return $this->convertRawResponseToInt($this->getOption('MIN_PHONETIC_TERM_LEN'));
    }

    public function setMinPhoneticTermLength(int $value = 3): bool
    {
        return $this->setOption('MIN_PHONETIC_TERM_LEN', $value);
    }
}