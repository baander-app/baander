#!/usr/bin/env bun
import { Command } from 'commander';
import { loadConfig } from './config.js';

import { createConsoleCommand } from './commands/console.js';
import { createPhpCommand } from './commands/php.js';
import { createComposerCommand } from './commands/composer.js';
import { createPhpunitCommand } from './commands/phpunit.js';
import { createParatestCommand } from './commands/paratest.js';
import { createStanCommand } from './commands/stan.js';
import { createDeptracCommand } from './commands/deptrac.js';
import { createMigrateCommand, createMigrateDevCommand } from './commands/migrate.js';
import { createLogsCommand } from './commands/logs.js';
import { createSshCommand, createSshRootCommand } from './commands/ssh.js';
import { createBuildCommand, createBuildProdCommand, createBuildCleanCommand } from './commands/build.js';
import { createStartCommand } from './commands/start.js';
import { createStopCommand } from './commands/stop.js';
import { createDownCommand } from './commands/down.js';
import { createRestartCommand, createRestartAppCommand } from './commands/restart.js';
import { createManifestCommand } from './commands/manifest-cmd.js';

import { error } from './lib/output.js';
import { BaanderError } from './lib/errors.js';

const VERSION = '1.0.0';

export function createProgram(): Command {
  const program = new Command();

  program
    .name('baander')
    .description('Baander development CLI')
    .version(VERSION)
    .option('--tty', 'Force TTY allocation', false)
    .option('--no-tty', 'Disable TTY allocation', false);

  const commands: Command[] = [
    createConsoleCommand(),
    createPhpCommand(),
    createComposerCommand(),
    createPhpunitCommand(),
    createParatestCommand(),
    createStanCommand(),
    createDeptracCommand(),
    createMigrateCommand(),
    createMigrateDevCommand(),
    createLogsCommand(),
    createSshCommand(),
    createSshRootCommand(),
    createBuildCommand(),
    createBuildProdCommand(),
    createBuildCleanCommand(),
    createStartCommand(),
    createStopCommand(),
    createDownCommand(),
    createRestartCommand(),
    createRestartAppCommand(),
    createManifestCommand(),
  ];

  for (const cmd of commands) {
    program.addCommand(cmd);
  }

  // Handle default command from config
  program.action(() => {
    const config = loadConfig();
    const defaultCmd = config.defaultCommand ?? 'help';

    if (defaultCmd === 'help') {
      program.help();
      return;
    }

    const cmd = program.commands.find(
      (c) => c.name() === defaultCmd,
    );

    if (cmd) {
      const origArgv = process.argv;
      process.argv = [origArgv[0], origArgv[1], defaultCmd];
      program.parse();
    } else {
      error(
        `Unknown defaultCommand "${defaultCmd}" in config. Showing help.`,
      );
      program.help();
    }
  });

  return program;
}

// Only run when executed directly (not imported)
const isMain =
  import.meta.path === process.argv[1] ||
  process.argv[1]?.endsWith('baander');

if (isMain) {
  const program = createProgram();

  try {
    program.parse();
  } catch (e: unknown) {
    if (e instanceof BaanderError) {
      error(e.message);
      process.exit(e.exitCode);
    }
    throw e;
  }
}
