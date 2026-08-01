import { Command } from 'commander';
import { dockerCompose } from '../lib/docker.js';

export function createStopCommand(): Command {
  return new Command('stop')
    .description('Stop all services')
    .action(async () => {
      const exitCode = await dockerCompose(['stop']);
      process.exit(exitCode);
    });
}
