<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use Throwable;

/**
 * The database refused a write because a value would exceed a member-input bound, a size limit the
 * request validation also enforces. The transaction port throws it in place of the check violation,
 * which it keeps as the previous exception, and the transaction was rolled back.
 *
 * Its reason differs from the playback session's stale-revision refusal, so a client that resyncs
 * on that one does not retry this one.
 */
final class MemberInputBoundException extends ConflictException
{
    public const string REASON = 'member_input_bound';

    public function __construct(Throwable $previous)
    {
        parent::__construct('The change would store a value larger than its limit.', ['reason' => self::REASON], $previous);
    }
}
