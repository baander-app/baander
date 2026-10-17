<?php

declare(strict_types=1);

namespace App\Media\Application\CommandHandler;

use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Media\Application\Port\ImageDerivativePortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class GenerateImageDerivativeHandler
{
    public function __construct(private ImageDerivativePortInterface $derivatives)
    {
    }

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(GenerateImageDerivativeCommand $command): void
    {
        $this->derivatives->generate($command->sourcePath, $command->preset);
    }
}
