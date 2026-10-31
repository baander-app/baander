import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { connect } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:tls';

export async function startHttpsProxy({ hostname, upstreamPort, port = 0 }) {
  if (!Number.isInteger(upstreamPort) || upstreamPort < 1 || upstreamPort > 65535
    || !Number.isInteger(port) || port < 0 || port > 65535) {
    throw new Error('The HTTPS fixture requires loopback TCP ports.');
  }
  const directory = mkdtempSync(join(tmpdir(), 'baander-browser-tls-'));
  const key = join(directory, 'key.pem');
  const cert = join(directory, 'cert.pem');
  const sockets = new Set();
  let server;
  try {
    execFileSync('openssl', [
      'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert,
      '-days', '1', '-subj', `/CN=${hostname}`,
    ], { stdio: 'ignore' });
    server = createServer({ key: readFileSync(key), cert: readFileSync(cert) }, (socket) => {
      const upstream = connect(upstreamPort, '127.0.0.1');
      sockets.add(socket);
      socket.pipe(upstream).pipe(socket);
      socket.on('error', () => upstream.destroy());
      socket.on('close', () => {
        sockets.delete(socket);
        upstream.destroy();
      });
      upstream.on('error', () => socket.destroy());
      upstream.on('close', () => {
        if (!upstream.readableEnded) socket.destroy();
      });
    });
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(port, '127.0.0.1', resolve);
    });
  } catch (error) {
    rmSync(directory, { recursive: true, force: true });
    throw error;
  }

  return {
    origin: `https://${hostname}:${server.address().port}`,
    async close() {
      for (const socket of sockets) socket.destroy();
      try {
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
      } finally {
        rmSync(directory, { recursive: true, force: true });
      }
    },
  };
}
