<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class LogCommandInput
{
    public static function configure(Command $command, bool $sql = false): void
    {
        AdminCommandSupport::addJsonOption($command);
        foreach (['source' => 'Opaque source ID (repeatable)', 'level' => 'Level (repeatable)', 'channel' => 'Match any selected channel (repeatable)'] as $name => $description) {
            $command->addOption($name, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $description);
        }
        foreach (['search' => 'Literal message search', 'correlation-id' => 'Correlation ID', 'since' => 'Start timestamp (RFC 3339)', 'until' => 'End timestamp (RFC 3339)', 'limit' => 'Maximum records (1–500)', 'cursor' => 'Opaque continuation cursor', 'order' => 'asc or desc'] as $name => $description) {
            if (!$sql || !in_array($name, ['limit', 'cursor'], true)) {
                $command->addOption($name, null, InputOption::VALUE_REQUIRED, $description);
            }
        }
    }

    public static function query(InputInterface $input, bool $sql = false): LogQuery
    {
        return new LogQuery(
            sources: $input->getOption('source'),
            search: AdminCommandSupport::stringOption($input, 'search') ?? '',
            levels: $input->getOption('level'),
            channels: $input->getOption('channel'),
            correlationId: AdminCommandSupport::stringOption($input, 'correlation-id'),
            since: AdminCommandSupport::stringOption($input, 'since'),
            until: AdminCommandSupport::stringOption($input, 'until'),
            limit: $sql ? 200 : (AdminCommandSupport::integerOption($input, 'limit') ?? 200),
            cursor: $sql ? null : AdminCommandSupport::stringOption($input, 'cursor'),
            order: AdminCommandSupport::stringOption($input, 'order') ?? 'desc',
        );
    }
}
