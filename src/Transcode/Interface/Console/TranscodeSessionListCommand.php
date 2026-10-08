<?php

declare(strict_types=1);

namespace App\Transcode\Interface\Console;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Interface\Resource\TranscodeSessionResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
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

    /** @param UserProviderInterface<UserInterface> $users finds a user by email address, as login does */
    public function __construct(
        private readonly TranscodeSessionPortInterface $sessions,
        private readonly UserProviderInterface $users,
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
                ? $this->sessions->findActiveByUser($this->userId($user))
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

    /**
     * @throws NotFoundException     when no user has the email address
     * @throws InvalidInputException when the value is neither an email address nor a UUID
     */
    private function userId(string $identifier): Uuid
    {
        try {
            $email = str_contains($identifier, '@') ? new Email($identifier) : null;
            if ($email === null) {
                return Uuid::fromString($identifier);
            }
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException('The user must be an email address or a UUID.', previous: $error);
        }

        try {
            $user = $this->users->loadUserByIdentifier($email->toString());
        } catch (UserNotFoundException $error) {
            throw new NotFoundException(sprintf('No user has the email address "%s".', $identifier), previous: $error);
        }

        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            throw new \LogicException(sprintf('The user provider returned a %s without a user ID.', get_debug_type($user)));
        }

        return Uuid::fromString($user->getId());
    }
}
