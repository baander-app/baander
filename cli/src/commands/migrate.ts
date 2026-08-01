import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';
import { info } from '../lib/output.js';

export function createMigrateCommand(): Command {
  return new Command('migrate')
    .description('Run Doctrine migrations (main + test databases)')
    .alias('m')
    .argument('[args...]', 'Extra migrate arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      info('Running migrations (main database)...');
      let exitCode = await execInContainer({
        args: [
          'php',
          'bin/console',
          'doctrine:migrations:migrate',
          '--no-interaction',
          ...args,
        ],
      });

      if (exitCode !== 0) {
        process.exit(exitCode);
      }

      info('Running migrations (test database)...');
      exitCode = await execInContainer({
        args: [
          'php',
          'bin/console',
          'doctrine:migrations:migrate',
          '--no-interaction',
          '--env=test',
          ...args,
        ],
      });

      process.exit(exitCode);
    });
}

export function createMigrateDevCommand(): Command {
  return new Command('migrate:dev')
    .description('Run Doctrine migrations (main database only)')
    .alias('md')
    .argument('[args...]', 'Extra migrate arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      info('Running migrations (main database)...');
      const exitCode = await execInContainer({
        args: [
          'php',
          'bin/console',
          'doctrine:migrations:migrate',
          '--no-interaction',
          ...args,
        ],
      });

      process.exit(exitCode);
    });
}
