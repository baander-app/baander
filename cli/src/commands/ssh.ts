import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';

export function createSshCommand(): Command {
  return new Command('ssh')
    .description('Open an interactive shell in the app container')
    .action(async () => {
      const exitCode = await execInContainer({
        args: ['bash'],
        tty: true,
      });

      process.exit(exitCode);
    });
}

export function createSshRootCommand(): Command {
  return new Command('ssh:root')
    .description('Open an interactive shell as root in the app container')
    .action(async () => {
      const exitCode = await execInContainer({
        args: ['bash'],
        tty: true,
        asRoot: true,
      });

      process.exit(exitCode);
    });
}
