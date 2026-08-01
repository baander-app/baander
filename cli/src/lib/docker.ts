import { spawn } from 'bun';
import { BaanderError, DockerNotFoundError } from './errors';
import { readFileSync, existsSync, statSync } from 'fs';
import { resolve } from 'path';

export interface DockerExecOptions {
  /** Command to run inside the container */
  args: string[];
  /** Force TTY allocation */
  tty?: boolean;
  /** Force no TTY */
  noTty?: boolean;
  /** Run as root instead of www-data */
  asRoot?: boolean;
  /** Extra environment variables to set in the container */
  env?: Record<string, string>;
  /** Working directory in container */
  workdir?: string;
}

export interface DockerLogsOptions {
  /** Follow logs */
  follow?: boolean;
  /** Number of lines to show */
  lines?: number;
  /** Container name (default: baander-app) */
  container?: string;
}

/** Detect if stdout is a TTY */
function shouldAllocateTty(opts: DockerExecOptions): boolean {
  if (opts.tty) return true;
  if (opts.noTty) return false;
  return process.stdout.isTTY ?? false;
}

/** Find the project root by walking up from cwd to find docker-compose.yml */
export function findProjectRoot(): string {
  let dir = process.cwd();
  for (let i = 0; i < 10; i++) {
    if (existsSync(resolve(dir, 'docker-compose.yml'))) {
      return dir;
    }
    const parent = resolve(dir, '..');
    if (parent === dir) break;
    dir = parent;
  }
  throw new BaanderError(
    'Not in a Baander project. Could not find docker-compose.yml.',
  );
}

/**
 * Execute a command inside the baander-app container via docker compose exec
 */
export async function execInContainer(
  opts: DockerExecOptions,
): Promise<number> {
  const projectRoot = findProjectRoot();
  const allocateTty = shouldAllocateTty(opts);

  const dockerArgs: string[] = [
    'compose',
    '--env-file',
    '.env',
    '-f',
    'docker-compose.yml',
    'exec',
  ];

  if (allocateTty) {
    dockerArgs.push('-t');
  }

  if (!opts.asRoot) {
    dockerArgs.push('-u', 'www-data');
  }

  if (opts.workdir) {
    dockerArgs.push('-w', opts.workdir);
  }

  // Pass extra env vars via -e flags
  if (opts.env) {
    for (const [key, value] of Object.entries(opts.env)) {
      dockerArgs.push('-e', `${key}=${value}`);
    }
  }

  dockerArgs.push('app', ...opts.args);

  return spawnDocker(dockerArgs, projectRoot);
}

/**
 * Execute a raw docker compose command (no exec — for lifecycle operations)
 */
export async function dockerCompose(
  args: string[],
): Promise<number> {
  const projectRoot = findProjectRoot();

  const dockerArgs: string[] = [
    'compose',
    '--env-file',
    '.env',
    '-f',
    'docker-compose.yml',
    ...args,
  ];

  return spawnDocker(dockerArgs, projectRoot);
}

/**
 * Stream container logs
 */
export async function containerLogs(opts: DockerLogsOptions = {}): Promise<number> {
  const {
    follow = false,
    lines = 100,
    container = 'baander-app',
  } = opts;

  const dockerArgs: string[] = ['logs'];

  if (follow) {
    dockerArgs.push('-f');
  }

  dockerArgs.push(`--tail`, String(lines), container);

  const projectRoot = findProjectRoot();

  return spawnDocker(dockerArgs, projectRoot);
}

/**
 * Core spawn helper — runs docker with proper stdio, signal forwarding, and error handling
 */
async function spawnDocker(
  args: string[],
  cwd: string,
): Promise<number> {
  let proc: ReturnType<typeof spawn> | null = null;

  try {
    proc = spawn({
      cmd: ['docker', ...args],
      cwd,
      env: {
        ...process.env,
        HOST_UID: String(process.getuid?.() ?? 1000),
        HOST_GID: String(process.getgid?.() ?? 1000),
      },
      stdout: 'inherit',
      stderr: 'inherit',
      stdin: 'inherit',
    });
  } catch (e: unknown) {
    if (e instanceof Error && (e as NodeJS.ErrnoException).code === 'ENOENT') {
      throw new DockerNotFoundError();
    }
    throw e;
  }

  // Forward signals
  const onSigInt = () => proc?.kill('SIGINT');
  const onSigTerm = () => proc?.kill('SIGTERM');
  process.on('SIGINT', onSigInt);
  process.on('SIGTERM', onSigTerm);

  const exitCode = await proc.exited;

  process.removeListener('SIGINT', onSigInt);
  process.removeListener('SIGTERM', onSigTerm);

  return exitCode;
}
