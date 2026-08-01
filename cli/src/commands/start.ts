import { Command } from 'commander';
import { dockerCompose } from '../lib/docker.js';

export function createStartCommand(): Command {
  return new Command('start')
    .description('Start all services')
    .action(async () => {
      const exitCode = await dockerCompose(['up', '-d']);
      process.exit(exitCode);
    });
}
