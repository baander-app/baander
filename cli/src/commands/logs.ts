import { Command } from 'commander';
import { containerLogs } from '../lib/docker.js';

export function createLogsCommand(): Command {
  const cmd = new Command('logs');

  cmd
    .description('View container logs (default: snapshot)')
    .alias('l')
    .option('-f, --follow', 'Follow log output', false)
    .option('--nginx', 'Show nginx container logs', false)
    .option('--all', 'Show all container logs', false)
    .option('-n, --lines <number>', 'Number of lines to show', '100')
    .action(
      async (opts: {
        follow: boolean;
        nginx: boolean;
        all: boolean;
        lines: string;
      }) => {
        let container = 'baander-app';
        if (opts.nginx) container = 'baander-nginx';

        if (opts.all) {
          // Use docker compose logs for all containers
          const { dockerCompose } = await import('../lib/docker.js');
          const args: string[] = ['logs'];
          if (opts.follow) args.push('-f');
          args.push('--tail', opts.lines);
          const exitCode = await dockerCompose(args);
          process.exit(exitCode);
          return;
        }

        const exitCode = await containerLogs({
          follow: opts.follow,
          lines: parseInt(opts.lines, 10),
          container,
        });

        process.exit(exitCode);
      },
    );

  return cmd;
}
