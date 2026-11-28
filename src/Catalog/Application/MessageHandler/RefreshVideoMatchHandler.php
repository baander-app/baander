<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Application\Port\VideoMatchRefresherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RefreshVideoMatchHandler
{
    public function __construct(private VideoMatchRefresherInterface $refresh) {}

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(RefreshVideoMatchMessage $message): void
    {
        $this->refresh->refresh($message);
    }
}
