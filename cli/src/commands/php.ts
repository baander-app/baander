import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createPhpCommand(): Command {
  return new Command('php')
    .description('Run a PHP command in the container')
    .argument('[args...]', 'PHP command and arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await execInContainer({
        args: ['php', ...args],
      });

      process.exit(exitCode);
    });
}
