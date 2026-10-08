<?php

declare(strict_types=1);

namespace App\Tests\Support\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Console commands Baander defines itself, as opposed to framework commands.
 *
 * Read from `#[AsCommand]` on the classes under `src/`, without instantiating a
 * command, so services that a command needs at construction do not have to be
 * configured.
 */
final class ProjectCommands
{
    /**
     * @return array<string, class-string> command name => class
     */
    public static function inDirectory(string $sourceDirectory): array
    {
        $commands = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php'
                || !str_contains((string) file_get_contents($file->getPathname()), '#[AsCommand')) {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($sourceDirectory) + 1, -4));
            if (!class_exists($class)) {
                throw new \LogicException(sprintf('%s declares a command but %s cannot be autoloaded.', $file->getPathname(), $class));
            }
            foreach ((new \ReflectionClass($class))->getAttributes(AsCommand::class) as $attribute) {
                // "name|alias" declares aliases; a leading "|" hides the command.
                $name = explode('|', ltrim($attribute->newInstance()->name, '|'))[0];
                $commands[$name] = $class;
            }
        }
        ksort($commands);

        return $commands;
    }
}
