import { Command } from 'commander';
import { dockerCompose } from '../lib/docker.js';
import { info } from '../lib/output.js';

export function createRestartCommand(): Command {
  return new Command('restart')
    .description('Stop and start all services')
    .action(async () => {
      info('Stopping services...');
      let exitCode = await dockerCompose(['stop']);
      if (exitCode !== 0) {
        process.exit(exitCode);
      }

      info('Starting services...');
      exitCode = await dockerCompose(['up', '-d']);
      process.exit(exitCode);
    });
}

export function createRestartAppCommand(): Command {
  return new Command('restart:app')
    .description('Restart app container only')
    .action(async () => {
      info('Restarting app container...');
      const exitCode = await dockerCompose(['restart', 'app']);
      process.exit(exitCode);
    });
}
