import { Command } from 'commander';
import { dockerCompose } from '../lib/docker.js';

export function createDownCommand(): Command {
  return new Command('down')
    .description('Stop and remove containers, networks')
    .action(async () => {
      const exitCode = await dockerCompose(['down']);
      process.exit(exitCode);
    });
}
