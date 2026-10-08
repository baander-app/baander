<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/transcode/sessions/{uuid}.
 *
 * The API shows a session to its owner or an administrator; the shell has full
 * authority and shows any session.
 */
#[AsCommand(
    name: 'app:transcode:session:show',
    description: 'Show one transcode session.',
)]
final class TranscodeSessionShowCommand extends Command
{
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'UUID of the session, as app:transcode:session:list prints it');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $session = $this->sessions->findByUuid($this->sessionId($input))
                ?? throw new NotFoundException('Session not found.');
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $data = TranscodeSessionResource::from($session);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->definitionList(
            ['UUID' => $data['uuid']],
            ['Public ID' => $data['publicId']],
            ['User' => $data['userId']],
            ['Job' => $data['jobId']],
            ['Video' => $data['videoId']],
            ['State' => $data['state']],
            ['Priority' => $data['priority']],
            ['Audio profile' => $data['audioProfile']['name'] ?? '-'],
            ['Current segment' => (string) $data['currentSegmentIndex']],
            ['Wall clock offset' => (string) $data['wallClockOffset']],
            ['Created' => $data['createdAt']],
            ['Updated' => $data['updatedAt']],
        );

        return Command::SUCCESS;
    }

    /** @throws InvalidInputException when the argument is not a UUID */
    private function sessionId(InputInterface $input): Uuid
    {
        try {
            return Uuid::fromString((string) $input->getArgument('uuid'));
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException('The session ID must be a UUID.', previous: $error);
        }
    }
}
