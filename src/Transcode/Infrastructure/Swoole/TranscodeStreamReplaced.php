<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

/** Internal interruption when a poll's captured stream was stopped or replaced. */
final class TranscodeStreamReplaced extends \RuntimeException
{
}
