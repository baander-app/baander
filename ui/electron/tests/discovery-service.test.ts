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
  const receive = (message: string, address = '192.168.1.10') => socket.emit('message', Buffer.from(message), {
    address,
    family: 'IPv4',
    port: 41234,
    size: Buffer.byteLength(message),
  });
  return { service, found, receive };
}

const acceptedUrls: [string, string, boolean][] = [
  ['http://192.168.1.10:8080/music', 'http://192.168.1.10:8080', false],
  ['http://10.0.0.7/', 'http://10.0.0.7', false],
  ['http://172.31.255.1:8080', 'http://172.31.255.1:8080', false],
  ['http://127.0.0.1:8080/music', 'http://127.0.0.1:8080', false],
  ['http://[::1]:8080/music', 'http://[::1]:8080', false],
  ['http://[fd12:3456::7]:8080/', 'http://[fd12:3456::7]:8080', false],
  ['http://nas.local:8080', 'http://nas.local:8080', false],
  ['HTTP://Media.Local/music', 'http://media.local', false],
  ['https://server.baander.app:8443/music?label="<server>"', 'https://server.baander.app:8443', true],
  ['https://203.0.113.5/', 'https://203.0.113.5', true],
];

for (const [url, origin, encrypted] of acceptedUrls) {
  test(`stores the origin ${origin} announced as ${url}`, async (t) => {
    t.mock.method(Date, 'now', () => 1000);
    const { service, found, receive } = await startDiscovery(t);
    receive(JSON.stringify({ ...announcement, url }));
    const expected = {
      name: announcement.name,
      url: origin,
      encrypted,
      address: '192.168.1.10',
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
      [`over-long ${field}`, JSON.stringify({ ...announcement, [field]: `${announcement[field as keyof typeof announcement]}${'x'.repeat(256)}` })],
    ];
  }),
  ...[
    '/music',
    '//server.baander.app/music',
    'ftp://server.baander.app/music',
    'http://',
    'http://203.0.113.5/',
    'http://media.baander.app/',
    'http://100.64.0.1/',
    'http://169.254.10.1/',
    'http://[2001:db8::1]/',
    'http://local/',
  ].map((url): [string, string] => [
    `URL ${url}`, JSON.stringify({ ...announcement, url }),
  ]),
];

for (const [description, message] of invalidMessages) {
  test(`refuses ${description} and accepts later valid traffic`, async (t) => {
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

test('repeat origins update only when lastSeen advances and remain sorted', async (t) => {
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
  receive(JSON.stringify({ ...announcement, url: 'http://127.0.0.1:8080/other', name: 'Updated server' }), '127.0.0.1');
  assert.equal(found.length, 3);
  assert.deepEqual(
    service.getServers().map(({ url, name, address, lastSeen }) => ({ url, name, address, lastSeen })),
    [
      { url: 'http://127.0.0.1:8080', name: 'Updated server', address: '127.0.0.1', lastSeen: 3000 },
      { url: 'https://second.baander.app', name: announcement.name, address: '192.168.1.10', lastSeen: 2000 },
    ],
  );
});

test('a 65th server evicts the server seen longest ago', async (t) => {
  let now = 1000;
  t.mock.method(Date, 'now', () => now);
  const { service, receive } = await startDiscovery(t);
  for (let index = 0; index < 65; index++) {
    now = 1000 + index;
    receive(JSON.stringify({ ...announcement, url: `https://server-${index}.baander.app` }));
  }

  const servers = service.getServers();
  assert.equal(servers.length, 64);
  assert.equal(servers.some((server) => server.url === 'https://server-0.baander.app'), false);
  assert.equal(servers[0].url, 'https://server-64.baander.app');
  assert.equal(servers.at(-1)?.url, 'https://server-1.baander.app');
});
