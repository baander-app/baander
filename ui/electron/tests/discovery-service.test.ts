import assert from 'node:assert/strict';
import dgram from 'node:dgram';
import { EventEmitter } from 'node:events';
import { test, type TestContext } from 'node:test';
import { DiscoveryService, type DiscoveredServer } from '../src/main/services/discovery.service.ts';

class FakeSocket extends EventEmitter {
  bind(_port: number, callback: () => void) { callback(); }
  setBroadcast(_enabled: boolean) {}
  send() {}
  close() {}
}

const announcement = {
  name: 'Bånder server',
  url: 'http://127.0.0.1:8080/music',
  version: 'development',
  api_version: 'next',
  timestamp: 'announcement time',
};

async function startDiscovery(t: TestContext) {
  const socket = new FakeSocket();
  t.mock.method(dgram, 'createSocket', () => socket);
  const service = new DiscoveryService({ additionalHosts: [] });
  t.after(() => service.stopScan());
  const found: DiscoveredServer[] = [];
  service.on('server-found', (server: DiscoveredServer) => found.push(server));
  await service.startScan();
  const receive = (message: string) => socket.emit('message', Buffer.from(message), {
    address: '127.0.0.1', family: 'IPv4', port: 41234, size: Buffer.byteLength(message),
  });
  return { service, found, receive };
}

for (const url of [
  'http://127.0.0.1:8080/music',
  'http://[::1]:8080/music',
  'http://server.baander.app:8080/music',
  'HTTPS://server.baander.app:8443/music?label="<server>"',
]) {
  test(`accepts and preserves URL ${url}`, async (t) => {
    t.mock.method(Date, 'now', () => 1000);
    const { service, found, receive } = await startDiscovery(t);
    receive(JSON.stringify({ ...announcement, url }));
    const expected = {
      name: announcement.name,
      url,
      version: announcement.version,
      apiVersion: announcement.api_version,
      timestamp: announcement.timestamp,
      lastSeen: 1000,
    };
    assert.deepEqual(service.getServers(), [expected]);
    assert.deepEqual(found, [expected]);
  });
}

const invalidMessages: [string, string][] = [
  ['malformed JSON', '{'],
  ['null', 'null'],
  ['an array', '[]'],
  ['a string', '"server"'],
  ...Object.keys(announcement).flatMap((field, index): [string, string][] => {
    const missing: Record<string, unknown> = { ...announcement };
    delete missing[field];
    const wrongType = [null, 42, true, {}, []][index];
    return [
      [`missing ${field}`, JSON.stringify(missing)],
      [`non-string ${field}`, JSON.stringify({ ...announcement, [field]: wrongType })],
    ];
  }),
  ...['/music', '//server.baander.app/music', 'ftp://server.baander.app/music', 'http://']
    .map((url): [string, string] => [
      `invalid URL ${url}`, JSON.stringify({ ...announcement, url }),
    ]),
];

for (const [description, message] of invalidMessages) {
  test(`ignores ${description} and accepts later valid traffic`, async (t) => {
    let now = 1000;
    t.mock.method(Date, 'now', () => now);
    const { service, found, receive } = await startDiscovery(t);
    receive(JSON.stringify(announcement));
    const before = service.getServers();
    now = 2000;
    assert.doesNotThrow(() => receive(message));
    assert.deepEqual(service.getServers(), before);
    assert.equal(found.length, 1);
    receive(JSON.stringify({ ...announcement, url: 'https://second.baander.app/music' }));
    assert.equal(service.getServers().length, 2);
    assert.equal(found.length, 2);
  });
}

test('repeat URLs update only when lastSeen advances and remain sorted', async (t) => {
  let now = 1000;
  t.mock.method(Date, 'now', () => now);
  const { service, found, receive } = await startDiscovery(t);
  receive(JSON.stringify(announcement));
  receive(JSON.stringify({ ...announcement, name: 'Same-time announcement' }));
  assert.equal(found.length, 1);
  assert.equal(service.getServers()[0].name, announcement.name);
  now = 2000;
  receive(JSON.stringify({ ...announcement, url: 'https://second.baander.app/music' }));
  now = 3000;
  receive(JSON.stringify({ ...announcement, name: 'Updated server' }));
  assert.equal(found.length, 3);
  assert.deepEqual(service.getServers().map(({ url, name, lastSeen }) => ({ url, name, lastSeen })), [
    { url: announcement.url, name: 'Updated server', lastSeen: 3000 },
    { url: 'https://second.baander.app/music', name: announcement.name, lastSeen: 2000 },
  ]);
});
