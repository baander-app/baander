import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createParatestCommand(): Command {
  const cmd = new Command('paratest');

  cmd
    .description('Run tests in parallel via Paratest')
    .alias('pt')
    .argument('[args...]', 'Paratest arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await execInContainer({
        args: [
          './vendor/bin/paratest',
          '--processes',
          'auto',
          '--tmp-dir',
          'var',
          ...args,
        ],
      });

      process.exit(exitCode);
    });

  return cmd;
}
