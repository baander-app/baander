<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final readonly class ValidatedSendersLocator implements SendersLocatorInterface
{
    public function __construct(
        private SendersLocatorInterface $inner,
        private AsyncWorkloadCatalog $catalog,
    ) {
    }

    public function getSenders(Envelope $envelope): iterable
    {
        if ($envelope->last(ReceivedStamp::class) !== null) {
            yield from $this->inner->getSenders($envelope);

            return;
        }

        $senders = iterator_to_array($this->inner->getSenders($envelope));

        $this->catalog->assertSenders($envelope->getMessage(), array_keys($senders));

        yield from $senders;
    }
}
