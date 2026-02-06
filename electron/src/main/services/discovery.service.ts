import dgram from 'dgram';
import { EventEmitter } from 'events';
import os from 'os';

export interface DiscoveredServer {
  name: string;
  url: string;
  version: string;
  apiVersion: string;
  timestamp: string;
  lastSeen: number;
}

export interface DiscoveryOptions {
  port?: number;
  timeout?: number; // How long to listen for responses (ms)
  broadcastInterval?: number; // How often to send broadcasts (ms)
  additionalHosts?: string[]; // Additional hosts to try (e.g., Docker VM IPs)
}

/**
 * Local Network Discovery Service for Electron
 *
 * Discovers Bånder servers on the local network via UDP broadcast.
 * Automatically detects common Docker environments (Colima, Docker Desktop).
 */
export class DiscoveryService extends EventEmitter {
  private socket: dgram.Socket | null = null;
  private readonly port: number;
  private readonly timeout: number;
  private readonly broadcastInterval: number;
  private readonly additionalHosts: string[];
  private discoveredServers = new Map<string, DiscoveredServer>();
  private broadcastTimer: NodeJS.Timeout | null = null;
  private timeoutTimer: NodeJS.Timeout | null = null;
  private isScanning = false;

  constructor(options: DiscoveryOptions = {}) {
    super();
    this.port = options.port ?? 41234;
    this.timeout = options.timeout ?? 5000; // 5 seconds default
    this.broadcastInterval = options.broadcastInterval ?? 1000; // Broadcast every 1 second
    this.additionalHosts = options.additionalHosts ?? this.detectLocalDockerHosts();
  }

  /**
   * Detect common local Docker environments
   * Returns IPs for Colima, Docker Desktop, etc.
   */
  private detectLocalDockerHosts(): string[] {
    const hosts: string[] = [];

    // Add localhost for native Docker or local development
    hosts.push('127.0.0.1');

    // Colima (macOS/Linux) - default VM IP
    hosts.push('192.168.64.2');

    // Docker Desktop (macOS) - default VM IP
    hosts.push('192.168.65.2');

    // Get local network interfaces
    const interfaces = os.networkInterfaces();
    for (const name in interfaces) {
      const addresses = interfaces[name];
      if (!addresses) continue;

      for (const addr of addresses) {
        // Add IPv4 addresses from local network interfaces
        if (addr.family === 'IPv4' && !addr.internal) {
          hosts.push(addr.address);
        }
      }
    }

    // Deduplicate while preserving order
    return Array.from(new Set(hosts));
  }

  /**
   * Start scanning for servers on the local network
   */
  async startScan(): Promise<void> {
    if (this.isScanning) {
      return;
    }

    this.isScanning = true;
    this.discoveredServers.clear();

    await this.createSocket();
    this.setupEventHandlers();
    this.startBroadcasting();
    this.startTimeout();

    this.emit('scan-started');
  }

  /**
   * Stop scanning for servers
   */
  stopScan(): void {
    if (!this.isScanning) {
      return;
    }

    this.isScanning = false;

    if (this.broadcastTimer) {
      clearInterval(this.broadcastTimer);
      this.broadcastTimer = null;
    }

    if (this.timeoutTimer) {
      clearTimeout(this.timeoutTimer);
      this.timeoutTimer = null;
    }

    if (this.socket) {
      this.socket.close();
      this.socket = null;
    }

    this.emit('scan-stopped', this.getServers());
  }

  /**
   * Get list of discovered servers
   */
  getServers(): DiscoveredServer[] {
    return Array.from(this.discoveredServers.values())
      .sort((a, b) => b.lastSeen - a.lastSeen);
  }

  /**
   * Create UDP socket for discovery
   */
  private async createSocket(): Promise<void> {
    return new Promise((resolve, reject) => {
      this.socket = dgram.createSocket('udp4');

      this.socket.on('error', (err) => {
        this.emit('error', err);
        reject(err);
      });

      this.socket.bind(0, () => {
        resolve();
      });
    });
  }

  /**
   * Setup message event handlers
   */
  private setupEventHandlers(): void {
    if (!this.socket) {
      return;
    }

    this.socket.on('message', (msg, rinfo) => {
      this.handleResponse(msg, rinfo);
    });
  }

  /**
   * Handle discovery response from server
   */
  private handleResponse(msg: Buffer, rinfo: dgram.RemoteInfo): void {
    try {
      const data = JSON.parse(msg.toString('utf-8'));
      const server: DiscoveredServer = {
        name: data.name,
        url: data.url,
        version: data.version,
        apiVersion: data.api_version,
        timestamp: data.timestamp,
        lastSeen: Date.now(),
      };

      // Use URL as unique key
      const key = server.url;
      const existing = this.discoveredServers.get(key);

      if (!existing || server.lastSeen > existing.lastSeen) {
        this.discoveredServers.set(key, server);
        this.emit('server-found', server);
      }
    } catch (err) {
      // Ignore invalid responses
      this.emit('error', new Error(`Invalid discovery response: ${err}`));
    }
  }

  /**
   * Start broadcasting discovery messages
   */
  private startBroadcasting(): void {
    // Send initial broadcast immediately
    this.broadcast();

    // Then broadcast at intervals
    this.broadcastTimer = setInterval(() => {
      this.broadcast();
    }, this.broadcastInterval);
  }

  /**
   * Broadcast discovery message to local network
   */
  private broadcast(): void {
    if (!this.socket) {
      return;
    }

    const message = Buffer.from('BAANDER_DISCOVER');

    try {
      // Broadcast to all network interfaces
      this.socket.setBroadcast(true);
      this.socket.send(message, 0, message.length, this.port, '255.255.255.255');

      // Also send to detected local hosts (Docker VMs, localhost, etc.)
      for (const host of this.additionalHosts) {
        try {
          this.socket.send(message, 0, message.length, this.port, host);
        } catch (err) {
          // Ignore errors for individual hosts - some may not be reachable
          // This is expected in mixed environments
        }
      }
    } catch (err) {
      this.emit('error', err);
    }
  }

  /**
   * Start scan timeout
   */
  private startTimeout(): void {
    this.timeoutTimer = setTimeout(() => {
      this.stopScan();
    }, this.timeout);
  }

  /**
   * Check if currently scanning
   */
  isScanningActive(): boolean {
    return this.isScanning;
  }
}

let discoveryInstance: DiscoveryService | null = null;

/**
 * Get or create the discovery service singleton
 */
export function getDiscoveryService(): DiscoveryService {
  if (!discoveryInstance) {
    discoveryInstance = new DiscoveryService();
  }
  return discoveryInstance;
}

/**
 * Shutdown the discovery service
 */
export function shutdownDiscoveryService(): void {
  if (discoveryInstance) {
    discoveryInstance.stopScan();
    discoveryInstance.removeAllListeners();
    discoveryInstance = null;
  }
}