<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The --lock and --unlock options of the app:album:update, app:song:update and app:artist:update commands.
 *
 * Each option may repeat and may list several fields separated by commas. Unlocks apply before the
 * edit and locks after it, so a run may unlock a field and change it, or change a field and lock it.
 */
final class FieldLockOptions
{
    public const string LOCK = 'lock';
    public const string UNLOCK = 'unlock';

    public static function add(Command $command): void
    {
        $command
            ->addOption(self::LOCK, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Lock a field, named as the API names it, so automatic metadata updates keep its value; applied after the edit, so the same run may change it')
            ->addOption(self::UNLOCK, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Unlock a field; applied before the edit, so the same run may change it');
    }

    /**
     * @return list<string> the field names the option lists
     */
    public static function read(InputInterface $input, string $option): array
    {
        $fields = [];
        foreach ((array) $input->getOption($option) as $value) {
            foreach (explode(',', is_scalar($value) ? (string) $value : '') as $field) {
                $field = trim($field);
                if ($field !== '') {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
