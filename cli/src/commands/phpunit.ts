import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createPhpunitCommand(): Command {
  const cmd = new Command('phpunit');

  cmd
    .description('Run PHPUnit tests')
    .alias('test')
    .option('--coverage', 'Generate coverage reports', false)
    .argument('[args...]', 'PHPUnit arguments')
    .allowUnknownOption(true)
    .action(async (args: string[], opts: { coverage: boolean }) => {
      const phpunitArgs: string[] = [
        './vendor/bin/phpunit',
        '-c',
        'phpunit.xml',
      ];

      if (opts.coverage) {
        phpunitArgs.push(
          '--coverage-html',
          'reports/coverage',
          '--coverage-clover',
          'reports/clover.xml',
          '--log-junit',
          'reports/junit.xml',
        );
      }

      phpunitArgs.push(...args);

      const exitCode = await execInContainer({
        args: phpunitArgs,
      });

      process.exit(exitCode);
    });

  return cmd;
}
