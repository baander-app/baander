import { Command } from 'commander';
import { execInContainer } from '../lib/docker.js';
import { loadManifest } from '../manifest.js';

export function createConsoleCommand(): Command {
  const cmd = new Command('console');

  cmd
    .description('Run a Symfony console command')
    .alias('c')
    .option('--list', 'List all available Symfony commands from manifest', false)
    .argument('[args...]', 'Console command and arguments')
    .allowUnknownOption(true)
    .action(async (args: string[], opts: { list: boolean }) => {
      if (opts.list) {
        await listCommands();
        return;
      }

      const consoleArgs = args.length > 0 ? args : ['list'];
      const exitCode = await execInContainer({
        args: ['php', 'bin/console', ...consoleArgs],
      });

      process.exit(exitCode);
    });

  return cmd;
}

async function listCommands(): Promise<void> {
  try {
    const manifest = await loadManifest();
    const commands = manifest.console.commands
      .filter((c) => !c.hidden)
      .sort((a, b) => a.name.localeCompare(b.name));

    if (commands.length === 0) {
      console.log('No commands found. Run `baander manifest` to generate.');
      return;
    }

    const maxNameLen = Math.max(...commands.map((c) => c.name.length));

    for (const cmd of commands) {
      const padding = ' '.repeat(Math.max(0, maxNameLen - cmd.name.length + 2));
      console.log(`  ${cmd.name}${padding}${cmd.description}`);
    }
  } catch {
    console.log('Run `baander manifest` first to generate the command list.');
  }
}
