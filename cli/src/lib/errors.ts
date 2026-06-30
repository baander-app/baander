export class BaanderError extends Error {
  constructor(
    message: string,
    public readonly exitCode: number = 1,
  ) {
    super(message);
    this.name = 'BaanderError';
  }
}

export class ContainerNotRunningError extends BaanderError {
  constructor() {
    super('Containers not running. Run `baander start` first.', 1);
    this.name = 'ContainerNotRunningError';
  }
}

export class DockerNotFoundError extends BaanderError {
  constructor() {
    super('docker compose not found. Install Docker Compose and try again.', 1);
    this.name = 'DockerNotFoundError';
  }
}

export class ManifestError extends BaanderError {
  constructor(message: string) {
    super(message, 1);
    this.name = 'ManifestError';
  }
}

export class ConfigError extends BaanderError {
  constructor(message: string) {
    super(message, 1);
    this.name = 'ConfigError';
  }
}
