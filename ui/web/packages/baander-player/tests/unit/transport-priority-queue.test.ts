import { describe, it, expect } from 'vitest';
import { SegmentPriorityQueue } from '../../src/core/transport/AdaptiveTransportLayer';
import type { FetchOutcome } from '../../src/types';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

interface QueuedItem {
  url: string;
  priority: number;
}

/** Create a pending request with a noop resolve. */
function makeRequest(url: string, priority: number): {
  req: Parameters<typeof SegmentPriorityQueue.prototype.enqueue>[0];
  item: QueuedItem;
} {
  const item: QueuedItem = { url, priority };
  const req = {
    url,
    priority,
    retryCount: 0,
    resolve: (_outcome: FetchOutcome) => {},
  };
  return { req, item };
}

/** Dequeue and collect URLs in order. */
function drainAll(queue: SegmentPriorityQueue): string[] {
  const urls: string[] = [];
  while (queue.size > 0) {
    const req = queue.dequeue();
    if (req) urls.push(req.url);
  }
  return urls;
}

// ===========================================================================
// SegmentPriorityQueue
// ===========================================================================

describe('SegmentPriorityQueue', () => {
  it('dequeues items in priority order (lower number = higher priority)', () => {
    const queue = new SegmentPriorityQueue();
    queue.enqueue(makeRequest('seg_5.m4s', 5).req);
    queue.enqueue(makeRequest('seg_1.m4s', 1).req);
    queue.enqueue(makeRequest('seg_3.m4s', 3).req);

    expect(queue.dequeue()!.url).toBe('seg_1.m4s');
    expect(queue.dequeue()!.url).toBe('seg_3.m4s');
    expect(queue.dequeue()!.url).toBe('seg_5.m4s');
  });

  it('returns undefined when dequeuing from empty queue', () => {
    const queue = new SegmentPriorityQueue();
    expect(queue.dequeue()).toBeUndefined();
  });

  it('tracks size correctly', () => {
    const queue = new SegmentPriorityQueue();
    expect(queue.size).toBe(0);

    queue.enqueue(makeRequest('a', 1).req);
    expect(queue.size).toBe(1);

    queue.enqueue(makeRequest('b', 2).req);
    queue.enqueue(makeRequest('c', 3).req);
    expect(queue.size).toBe(3);

    queue.dequeue();
    expect(queue.size).toBe(2);

    queue.dequeue();
    queue.dequeue();
    expect(queue.size).toBe(0);
  });

  it('does NOT guarantee FIFO order for equal priorities (binary heap property)', () => {
    // Standard binary min-heaps do not guarantee insertion order for equal
    // priorities. This is expected behaviour — if FIFO is needed, use a
    // tiebreaker (e.g. sequence number) in the priority value.
    const queue = new SegmentPriorityQueue();
    queue.enqueue(makeRequest('first.m4s', 3).req);
    queue.enqueue(makeRequest('second.m4s', 3).req);
    queue.enqueue(makeRequest('third.m4s', 3).req);

    // All three should be dequeued, but order among equal-priority items
    // is NOT guaranteed to be FIFO.
    const urls = drainAll(queue);
    expect(urls).toHaveLength(3);
    expect(urls.sort()).toEqual(['first.m4s', 'second.m4s', 'third.m4s']);
  });

  it('handles interleaved enqueue/dequeue maintaining heap property', () => {
    const queue = new SegmentPriorityQueue();

    queue.enqueue(makeRequest('seg_5', 5).req);
    queue.enqueue(makeRequest('seg_2', 2).req);
    // Dequeue highest priority → seg_2
    expect(queue.dequeue()!.url).toBe('seg_2');

    queue.enqueue(makeRequest('seg_1', 1).req);
    queue.enqueue(makeRequest('seg_8', 8).req);
    queue.enqueue(makeRequest('seg_3', 3).req);

    // Remaining: 1, 3, 5, 8
    expect(queue.dequeue()!.url).toBe('seg_1');
    expect(queue.dequeue()!.url).toBe('seg_3');
    expect(queue.dequeue()!.url).toBe('seg_5');
    expect(queue.dequeue()!.url).toBe('seg_8');
    expect(queue.dequeue()).toBeUndefined();
  });

  it('handles bulk enqueue then sequential dequeue', () => {
    const queue = new SegmentPriorityQueue();
    const priorities = [10, 2, 7, 1, 5, 3, 9, 4, 8, 6];

    for (const p of priorities) {
      queue.enqueue(makeRequest(`seg_${p}`, p).req);
    }

    // Should drain in ascending priority order
    const urls = drainAll(queue);
    expect(urls).toEqual([
      'seg_1', 'seg_2', 'seg_3', 'seg_4', 'seg_5',
      'seg_6', 'seg_7', 'seg_8', 'seg_9', 'seg_10',
    ]);
  });

  it('handles single element enqueue/dequeue cycle', () => {
    const queue = new SegmentPriorityQueue();

    queue.enqueue(makeRequest('only.m4s', 1).req);
    expect(queue.size).toBe(1);
    expect(queue.dequeue()!.url).toBe('only.m4s');
    expect(queue.size).toBe(0);
    expect(queue.dequeue()).toBeUndefined();

    // Can add again after emptying
    queue.enqueue(makeRequest('second.m4s', 1).req);
    expect(queue.dequeue()!.url).toBe('second.m4s');
  });

  it('handles priority 0 (highest possible)', () => {
    const queue = new SegmentPriorityQueue();
    queue.enqueue(makeRequest('normal', 5).req);
    queue.enqueue(makeRequest('urgent', 0).req);
    queue.enqueue(makeRequest('low', 10).req);

    expect(queue.dequeue()!.url).toBe('urgent');
    expect(queue.dequeue()!.url).toBe('normal');
    expect(queue.dequeue()!.url).toBe('low');
  });

  it('handles large number of items without losing ordering', () => {
    const queue = new SegmentPriorityQueue();
    const count = 100;

    // Insert in reverse order
    for (let i = count; i >= 1; i--) {
      queue.enqueue(makeRequest(`seg_${i.toString().padStart(3, '0')}`, i).req);
    }

    // Drain should be ascending
    let prevPriority = -1;
    while (queue.size > 0) {
      const req = queue.dequeue()!;
      expect(req.priority).toBeGreaterThan(prevPriority);
      prevPriority = req.priority;
    }
  });

  it('handles duplicate URLs with different priorities', () => {
    const queue = new SegmentPriorityQueue();
    queue.enqueue(makeRequest('seg_0.m4s', 10).req);
    queue.enqueue(makeRequest('seg_0.m4s', 1).req);

    // Both should be present, lower priority first
    const first = queue.dequeue()!;
    expect(first.url).toBe('seg_0.m4s');
    expect(first.priority).toBe(1);

    const second = queue.dequeue()!;
    expect(second.url).toBe('seg_0.m4s');
    expect(second.priority).toBe(10);
  });

  it('maintains heap property after many enqueue/dequeue cycles', () => {
    const queue = new SegmentPriorityQueue();

    // Simulate a realistic segment scheduling pattern:
    // - init segment (priority 0)
    // - near segments (priority 1-3)
    // - far segments (priority 5-10)
    queue.enqueue(makeRequest('init.mp4', 0).req);
    expect(queue.dequeue()!.url).toBe('init.mp4');

    for (let i = 0; i < 5; i++) {
      queue.enqueue(makeRequest(`seg_${i}.m4s`, i + 1).req);
    }

    // Dequeue first 2
    expect(queue.dequeue()!.priority).toBe(1);
    expect(queue.dequeue()!.priority).toBe(2);

    // Add more
    queue.enqueue(makeRequest('seg_5.m4s', 1).req);
    queue.enqueue(makeRequest('seg_6.m4s', 8).req);

    // Next should be priority 1 (seg_5 newly added)
    expect(queue.dequeue()!.priority).toBe(1);
    // Then priority 3 (seg_2 from original batch)
    expect(queue.dequeue()!.priority).toBe(3);
    // Then priority 4
    expect(queue.dequeue()!.priority).toBe(4);
    // Then priority 5
    expect(queue.dequeue()!.priority).toBe(5);
    // Then priority 8 (seg_6)
    expect(queue.dequeue()!.priority).toBe(8);
    // Empty
    expect(queue.dequeue()).toBeUndefined();
  });
});
