<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\DTO\LoginBlockPage;
use App\Auth\Application\Query\LoginBlock\ListLoginBlocksQuery;
use App\Auth\Interface\Resource\LoginBlockResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** The CLI counterpart of GET /api/admin/login-blocks. */
#[AsCommand(
    name: 'app:login-block:list',
    description: 'List the login honeypot\'s blocks, newest first, one page at a time.',
)]
final class LoginBlockListCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Blocks per page, 1-%d', ListLoginBlocksQuery::MAX_LIMIT), (string) ListLoginBlocksQuery::DEFAULT_LIMIT)
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Blocks to skip', '0');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $offset = AdminCommandSupport::integerOption($input, 'offset') ?? 0;
            $page = $this->support->dispatch(new ListLoginBlocksQuery(
                AdminCommandSupport::integerOption($input, 'limit') ?? ListLoginBlocksQuery::DEFAULT_LIMIT,
                $offset,
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }
        assert($page instanceof LoginBlockPage);

        $blocks = LoginBlockResource::collection($page->blocks);
        $exitCode = AdminCommandSupport::list(
            $input,
            $io,
            $blocks,
            ['IP address', 'Email', 'Field value', 'User agent', 'Blocked', 'ID'],
            static fn (array $block): array => [
                $block['ipAddress'],
                $block['email'] !== '' ? $block['email'] : '-',
                $block['fieldValue'],
                $block['userAgent'],
                $block['createdAt'],
                $block['id'],
            ],
            'No login blocks are recorded.',
        );

        if (!AdminCommandSupport::wantsJson($input) && $blocks !== []) {
            $io->text(sprintf('Blocks %d-%d of %d.', $offset + 1, $offset + count($blocks), $page->total));
        }

        return $exitCode;
    }
}
