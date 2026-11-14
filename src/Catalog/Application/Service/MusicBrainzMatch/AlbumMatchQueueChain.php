<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Shared\Domain\Model\Uuid;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Which chain of runs works the album match queue. Each run queues the next one, so the runs form
 * a chain; the queue keeps one chain, named by a token every run of it carries. A run that starts a
 * chain replaces any other, and a run of a replaced chain ends without matching or queuing a
 * successor, so a chain started to replace a lost one never leaves two chains behind.
 *
 * The current chain is held in the application cache for as long as its next run needs, plus a
 * margin. A chain whose next run never comes lets the hold lapse, and the queue's recovery then
 * starts a new chain. Losing the cache entry early at worst lets a second chain start, and the
 * older one then ends at its next run.
 */
final readonly class AlbumMatchQueueChain
{
    private const string KEY = 'catalog.album_match_queue.chain';

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Starts a chain in place of any other, held for $seconds.
     *
     * @param int<1, max> $seconds
     *
     * @return string the new chain's token
     */
    public function start(int $seconds): string
    {
        $chain = Uuid::v7()->toString();
        $this->store($chain, $seconds);

        return $chain;
    }

    /**
     * Holds $chain for $seconds more when it is the current chain or no chain is current.
     *
     * @param int<1, max> $seconds
     *
     * @return bool false when another chain replaced it; its run then ends
     */
    public function hold(string $chain, int $seconds): bool
    {
        $current = $this->cache->getItem(self::KEY);
        if ($current->isHit() && $current->get() !== $chain) {
            return false;
        }

        $this->store($chain, $seconds);

        return true;
    }

    /** Ends $chain when it is the current one, once the queue has nothing left to wait for. */
    public function release(string $chain): void
    {
        $current = $this->cache->getItem(self::KEY);
        if ($current->isHit() && $current->get() === $chain) {
            $this->cache->deleteItem(self::KEY);
        }
    }

    /** Whether a chain holds the queue. */
    public function isRunning(): bool
    {
        return $this->cache->getItem(self::KEY)->isHit();
    }

    /** @param int<1, max> $seconds */
    private function store(string $chain, int $seconds): void
    {
        $this->cache->save($this->cache->getItem(self::KEY)->set($chain)->expiresAfter($seconds));
    }
}
