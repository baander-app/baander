<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Storage;

use Baander\RedisRateLimiter\Limiter\ContinuousTokenBucket;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;

#[Exclude]
final readonly class RateLimiterStateCodec
{
    private string $fingerprint;
    private string $class;
    /** The window length in seconds; a token bucket has none. */
    private ?int $interval;
    private int $limit;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, private string $id)
    {
        $this->fingerprint = hash('sha256', serialize($config));
        $this->class = match ($config['policy']) {
            'fixed_window' => Window::class,
            'sliding_window' => SlidingWindow::class,
            'token_bucket' => ContinuousTokenBucket::class,
            default => throw new \InvalidArgumentException('Unsupported atomic rate limiter policy.'),
        };
        $this->limit = $config['limit'];
        $this->interval = $this->class === ContinuousTokenBucket::class
            ? null
            : RateLimiterInterval::seconds($config['interval']);
    }

    public function encode(LimiterStateInterface $state): string
    {
        $this->validate($state);

        return json_encode(['config' => $this->fingerprint, 'state' => base64_encode(serialize($state))], JSON_THROW_ON_ERROR);
    }

    public function decode(string $payload): ?LimiterStateInterface
    {
        if ($payload === '') {
            return null;
        }
        try {
            if (strlen($payload) > 16384) {
                throw new RateLimiterUnavailableException();
            }
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_keys($data) !== ['config', 'state']
                || $data['config'] !== $this->fingerprint || !is_string($data['state'])
            ) {
                throw new RateLimiterUnavailableException();
            }
            $serialized = base64_decode($data['state'], true);
            if ($serialized === false) {
                throw new RateLimiterUnavailableException();
            }
            $state = @unserialize($serialized, ['allowed_classes' => [$this->class], 'max_depth' => 8]);
            if (!$state instanceof LimiterStateInterface || serialize($state) !== $serialized) {
                throw new RateLimiterUnavailableException();
            }
            $this->validate($state);

            return $state;
        } catch (\Throwable) {
            throw new RateLimiterUnavailableException();
        }
    }

    private function validate(LimiterStateInterface $state): void
    {
        if ($state::class !== $this->class || $state->getId() !== $this->id) {
            throw new RateLimiterUnavailableException();
        }
        if ($state instanceof ContinuousTokenBucket) {
            if (!$this->validTime($state->refilledAt)) {
                throw new RateLimiterUnavailableException();
            }

            return;
        }
        if ($state instanceof Window) {
            $data = $state->__serialize();
            $keys = array_keys($data);
            $values = array_values($data);
            if (count($data) !== 2 || !is_string($keys[1]) || strlen($keys[1]) !== 8
                || !$this->validTime($values[0]) || $values[1] !== $this->limit
            ) {
                throw new RateLimiterUnavailableException();
            }
            $counts = unpack('Nhits/Ninterval', $keys[1]);
        } elseif ($state instanceof SlidingWindow) {
            // The limiter sets the window's size to its limit before every save; the size sits under the empty key.
            $data = $state->__serialize();
            $key = array_key_first($data);
            if (count($data) !== 2 || !is_string($key) || strlen($key) !== 12 + strlen($this->id)
                || !$this->validTime($data[$key]) || ($data[''] ?? null) !== $this->limit
            ) {
                throw new RateLimiterUnavailableException();
            }
            $counts = unpack('Nhits/Nprevious/Ninterval', substr($key, 0, 12));
        } else {
            throw new RateLimiterUnavailableException();
        }
        if ($counts === false || $counts['interval'] !== $this->interval) {
            throw new RateLimiterUnavailableException();
        }
    }

    private function validTime(mixed $value): bool
    {
        return is_float($value) && is_finite($value) && $value >= 0;
    }
}
