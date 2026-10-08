<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Interface\Resource\FailedMessageResource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI counterpart of GET /api/monitor/transport/failed.
 */
#[AsCommand(
    name: 'app:failed-message:list',
    description: 'List the messages held by the failure transport, newest first, including those waiting out a retry delay.',
)]
final class FailedMessageListCommand extends Command
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly FailedMessageAdministrationInterface $failedMessages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Messages per page, at most %d', self::MAX_LIMIT), (string) self::DEFAULT_LIMIT);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = max(1, (int) $input->getOption('page'));
        $limit = min(self::MAX_LIMIT, max(1, (int) $input->getOption('limit')));

        try {
            $result = $this->failedMessages->page($page, $limit);
        } catch (FailureTransportUnavailableException $e) {
            $io->getErrorStyle()->error(sprintf('Failure transport unavailable: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $items = FailedMessageResource::collection($result->messages);
        $exitCode = AdminCommandSupport::list(
            $input,
            $io,
            $items,
            ['ID', 'Message', 'Transport', 'Error', 'Failed at', 'Retries'],
            static fn (array $message): array => [
                $message['id'],
                $message['messageClass'],
                $message['originalTransport'] ?? '-',
                self::error($message['errorClass'], $message['errorMessage']),
                $message['failedAt'] ?? '-',
                $message['retryCount'],
            ],
            'The failure transport holds no messages.',
        );

        if (!AdminCommandSupport::wantsJson($input) && $result->total > 0) {
            $io->text(sprintf(
                'Page %d of %d, %d %s in total.',
                $page,
                max(1, (int) ceil($result->total / $limit)),
                $result->total,
                $result->total === 1 ? 'message' : 'messages',
            ));
        }

        return $exitCode;
    }

    private static function error(mixed $class, mixed $message): string
    {
        if (!is_string($class)) {
            return is_string($message) ? $message : '-';
        }
        $short = substr(strrchr('\\' . $class, '\\') ?: $class, 1);

        return is_string($message) ? sprintf('%s: %s', $short, $message) : $short;
    }
}
