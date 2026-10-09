<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\CatalogDeletionResult;
use App\Catalog\Application\Query\FileDeletionPreview;
use App\Catalog\Interface\Resource\CatalogDeletionResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The options and output the app:album:delete, app:song:delete, app:movie:delete and
 * app:artist:delete commands share.
 */
final class CatalogDeleteOutput
{
    public const string DELETE_FILES = 'delete-files';
    public const string DRY_RUN = 'dry-run';

    /** Adds --delete-files and --dry-run, for the album and song deletes. */
    public static function addFileOptions(Command $command, string $files): void
    {
        $command
            ->addOption(self::DELETE_FILES, null, InputOption::VALUE_NONE, sprintf('Also delete %s from disk, inside the library root only; requires --force', $files))
            ->addOption(self::DRY_RUN, null, InputOption::VALUE_NONE, 'Print what the delete would remove and change nothing; with --delete-files, check each file');
    }

    /**
     * Deleting audio files needs --force even on a terminal, so a confirmation typed out of habit
     * cannot delete them.
     *
     * @return int|null null when the command may go ahead, otherwise INVALID
     */
    public static function refuseFilesWithoutForce(InputInterface $input, SymfonyStyle $io): ?int
    {
        if ($input->getOption(self::DELETE_FILES) !== true || $input->getOption(AdminCommandSupport::FORCE_OPTION) === true) {
            return null;
        }

        $io->getErrorStyle()->error('--delete-files deletes audio files from disk and needs --force. Nothing was changed.');

        return Command::INVALID;
    }

    /**
     * Prints the result, as the API's `data` with --json, and returns FAILURE when a requested
     * file was left on disk.
     *
     * @param mixed $result the CatalogDeletionResult the delete returned
     */
    public static function result(InputInterface $input, SymfonyStyle $io, mixed $result, string $deleted): int
    {
        assert($result instanceof CatalogDeletionResult);
        $exitCode = $result->leftAny() ? Command::FAILURE : Command::SUCCESS;

        if (AdminCommandSupport::wantsJson($input)) {
            AdminCommandSupport::json($io, CatalogDeletionResource::from($result));

            return $exitCode;
        }

        $counts = [];
        foreach ($result->deleted as $kind => $count) {
            $counts[] = sprintf('%s: %d', $kind, $count);
        }
        $io->success(sprintf('%s deleted (%s).', $deleted, implode(', ', $counts)));

        if ($result->removed !== [] || $result->missing !== []) {
            $io->text(sprintf('Files removed: %d; already missing: %d.', count($result->removed), count($result->missing)));
        }
        if ($result->leftAny()) {
            $errors = $io->getErrorStyle();
            $errors->error(sprintf('%d file(s) were left on disk; the next scan imports them again.', count($result->left)));
            $errors->listing(array_map(
                static fn (array $file): string => sprintf('%s (%s: %s)', $file['path'], $file['reason'], $file['detail']),
                $result->left,
            ));
        }

        return $exitCode;
    }

    /** Prints the file checks of a preview. */
    public static function fileChecks(SymfonyStyle $io, FileDeletionPreview $preview): void
    {
        $io->table(
            ['Path', 'Verdict'],
            array_map(
                static fn (array $file): array => [$file['path'], $file['directory'] !== null ? sprintf('%s (%s)', $file['verdict'], $file['directory']) : $file['verdict']],
                $preview->files,
            ),
        );

        if ($preview->allowed) {
            $io->text('The files can be deleted.');

            return;
        }

        $io->warning($preview->scanInProgress
            ? 'A scan holds the library; a delete with --delete-files would be refused.'
            : 'A delete with --delete-files would be refused; nothing would be deleted.');
    }
}
