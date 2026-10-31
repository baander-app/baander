import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { startHttpsProxy } from '../../../tests/browser/https-proxy.mjs';

const port = Number(process.argv[2]);
if (!Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error('A disposable website HTTPS port is required.');
}

const reservation = createServer();
await new Promise((resolve, reject) => {
  reservation.once('error', reject);
  reservation.listen(0, '127.0.0.1', resolve);
});
const phpPort = reservation.address().port;
await new Promise((resolve, reject) => reservation.close((error) => error ? reject(error) : resolve()));

const proxy = await startHttpsProxy({ hostname: 'website.baander.app', upstreamPort: phpPort, port });
const php = spawn('php', [
  '-S', `127.0.0.1:${phpPort}`, '-t', 'var/website', 'scripts/website-router.php',
], { stdio: 'inherit' });
php.once('error', (error) => {
  console.error(error);
  process.exitCode = 1;
  proxy.close().catch(console.error);
});
php.once('exit', (code) => {
  process.exitCode = code ?? 0;
  proxy.close().catch(console.error);
});
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => php.kill(signal));
}
