const RESET = '\x1b[0m';
const RED = '\x1b[31m';
const GREEN = '\x1b[32m';
const YELLOW = '\x1b[33m';
const CYAN = '\x1b[36m';
const GRAY = '\x1b[90m';
const BOLD = '\x1b[1m';

function supportsColor(): boolean {
  return process.stdout.isTTY ?? false;
}

export function error(message: string): void {
  if (supportsColor()) {
    console.error(`${RED}${BOLD}Error:${RESET} ${message}`);
  } else {
    console.error(`Error: ${message}`);
  }
}

export function warn(message: string): void {
  if (supportsColor()) {
    console.warn(`${YELLOW}Warning:${RESET} ${message}`);
  } else {
    console.warn(`Warning: ${message}`);
  }
}

export function success(message: string): void {
  if (supportsColor()) {
    console.log(`${GREEN}${message}${RESET}`);
  } else {
    console.log(message);
  }
}

export function info(message: string): void {
  if (supportsColor()) {
    console.log(`${CYAN}${message}${RESET}`);
  } else {
    console.log(message);
  }
}

export function dim(message: string): void {
  if (supportsColor()) {
    console.log(`${GRAY}${message}${RESET}`);
  } else {
    console.log(message);
  }
}
