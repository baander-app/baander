import { Command } from 'commander';
import { dockerCompose } from '../lib/docker.js';

export function createBuildCommand(): Command {
  return new Command('build')
    .description('Build dev Docker images')
    .alias('b')
    .argument('[args...]', 'Extra build arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await dockerCompose([
        '--progress=plain',
        'build',
        ...args,
      ]);
      process.exit(exitCode);
    });
}

export function createBuildProdCommand(): Command {
  return new Command('build:prod')
    .description('Build production Docker images')
    .argument('[args...]', 'Extra build arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await dockerCompose([
        '--progress=plain',
        'build',
        '--target',
        'production',
        ...args,
      ]);
      process.exit(exitCode);
    });
}

export function createBuildCleanCommand(): Command {
  return new Command('build:clean')
    .description('Build Docker images without cache')
    .argument('[args...]', 'Extra build arguments')
    .allowUnknownOption(true)
    .action(async (args: string[]) => {
      const exitCode = await dockerCompose([
        '--progress=plain',
        'build',
        '--no-cache',
        ...args,
      ]);
      process.exit(exitCode);
    });
}
