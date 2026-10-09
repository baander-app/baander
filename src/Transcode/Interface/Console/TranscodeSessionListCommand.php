<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of GET /api/transcode/sessions/.
 *
 * The admin transcode tab lists the viewing admin's own sessions. The shell has no
 * viewer, so the command lists the active sessions of every user, and `--user`
 * narrows the list to one of them.
 */
#[AsCommand(
    name: 'app:transcode:session:list',
    description: 'List the active transcode sessions of every user, or of one user with --user.',
)]
final class TranscodeSessionListCommand extends Command
{
    private const string USER_OPTION = 'user';

    /** @param UserIdentifierResolverInterface $users finds the user `--user` names, as the `app:user:*` commands do */
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessions,
        private readonly UserIdentifierResolverInterface $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(self::USER_OPTION, null, InputOption::VALUE_REQUIRED, 'List only the sessions of this user, by email address or UUID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $input->getOption(self::USER_OPTION);

        try {
            $sessions = is_string($user)
                ? $this->sessions->findActiveByUser($this->users->userId($user))
                : $this->sessions->findActive();
        } catch (Throwable $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }

        return AdminCommandSupport::list(
            $input,
            $io,
            TranscodeSessionResource::collection($sessions),
            ['UUID', 'User', 'Video', 'State', 'Priority', 'Audio', 'Segment', 'Created'],
            static fn (array $session): array => [
                $session['uuid'],
                $session['userId'],
                $session['videoId'],
                $session['state'],
                $session['priority'],
                $session['audioProfile']['name'] ?? '-',
                (string) $session['currentSegmentIndex'],
                $session['createdAt'],
            ],
            'No transcode session is active.',
        );
    }
}
