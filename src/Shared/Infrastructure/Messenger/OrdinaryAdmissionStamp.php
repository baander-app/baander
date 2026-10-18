<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

#[Exclude]
final readonly class OrdinaryAdmissionStamp implements NonSendableStampInterface
{
    public function __construct(public string $requestId)
    {
    }
}
