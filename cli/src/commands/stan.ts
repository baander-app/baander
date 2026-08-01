import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createStanCommand(): Command {
  return new Command('stan')
    .description('Run PHPStan static analysis')
    .argument('[args...]', 'PHPStan arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await execInContainer({
        args: [
          'php',
          './vendor/bin/phpstan',
          'analyse',
          '--memory-limit=512M',
          ...args,
        ],
        env: { XDEBUG_MODE: 'off' },
      });

      process.exit(exitCode);
    });
}
