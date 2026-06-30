import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createComposerCommand(): Command {
  const cmd = new Command('composer');

  cmd
    .description('Run a Composer command')
    .alias('comp')
    .argument('[args...]', 'Composer command and arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await execInContainer({
        args: ['composer', ...args],
      });

      process.exit(exitCode);
    });

  return cmd;
}
