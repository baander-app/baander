<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\DTO\UserPage;
use App\Auth\Application\Query\User\ListUsersQuery;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/users. */
#[AsCommand(
    name: 'app:user:list',
    description: 'List users, newest first, one page at a time.',
)]
final class UserListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Only users with this role: ROLE_USER, ROLE_ADMIN or ROLE_SUPER_ADMIN')
            ->addOption('disabled', null, InputOption::VALUE_OPTIONAL, 'Only disabled users; --disabled=false lists only enabled users', false)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Users per page, 1-%d', ListUsersQuery::MAX_LIMIT), (string) ListUsersQuery::DEFAULT_LIMIT)
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Users to skip', '0');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $offset = AdminCommandSupport::integerOption($input, 'offset') ?? 0;
            $page = $this->support->dispatch(new ListUsersQuery(
                role: self::role($input),
                disabled: self::disabled($input),
                limit: AdminCommandSupport::integerOption($input, 'limit') ?? ListUsersQuery::DEFAULT_LIMIT,
                offset: $offset,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($page instanceof UserPage);

        $users = AdminUserResource::collection($page->users);
        $exitCode = AdminCommandSupport::list(
            $input,
            $io,
            $users,
            ['Email', 'Name', 'Roles', 'Disabled', 'UUID', 'Created'],
            static fn (array $user): array => [
                $user['email'],
                $user['name'],
                implode(', ', $user['roles']),
                AdminCommandSupport::yesNo($user['disabled']),
                $user['id'],
                $user['createdAt'],
            ],
            'No users match.',
        );

        if (!AdminCommandSupport::wantsJson($input) && $users !== []) {
            $io->text(sprintf('Users %d-%d of %d.', $offset + 1, $offset + count($users), $page->total));
        }

        return $exitCode;
    }

    private static function role(InputInterface $input): ?string
    {
        $role = $input->getOption('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /** Absent: every user. `--disabled` or `--disabled=true`: disabled users. `--disabled=false`: enabled users. */
    private static function disabled(InputInterface $input): ?bool
    {
        $value = $input->getOption('disabled');
        if ($value === false) {
            return null;
        }
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new InvalidInputException('--disabled must be true or false.');
    }
}
