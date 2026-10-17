<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/transcode/sessions/{publicId}.
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
        private readonly TranscodePublicViewInterface $publicView,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publicId', InputArgument::REQUIRED, 'Public identifier of the session');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $argument = $input->getArgument('publicId');
            try {
                $publicId = PublicId::fromString($argument);
            } catch (\InvalidArgumentException) {
                throw new InvalidInputException('The session ID must be a public identifier.');
            }
            $session = $this->sessions->findByPublicId($publicId)
                ?? throw new NotFoundException('Session not found.');
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        $data = TranscodeSessionResource::from($this->publicView->session($session));
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $data);
        }

        $io->definitionList(
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
}
