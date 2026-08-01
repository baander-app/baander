import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createDeptracCommand(): Command {
  return new Command('deptrac')
    .description('Run Deptrac architecture analysis')
    .argument('[args...]', 'Deptrac arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await execInContainer({
        args: [
          'vendor/bin/deptrac',
          'analyse',
          '--no-cache',
          '--no-progress',
          ...args,
        ],
      });

      process.exit(exitCode);
    });
}
