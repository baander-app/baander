import { defineConfig } from 'vite'
import { fileURLToPath } from 'node:url'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { execFileSync } from 'node:child_process'

const directory = mkdtempSync(join(tmpdir(), 'baander-typography-'))
const key = join(directory, 'key.pem'), cert = join(directory, 'cert.pem')
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert, '-days', '1', '-subj', '/CN=typography.baander.app'], { stdio: 'ignore' })

export default defineConfig({
  root: fileURLToPath(new URL('.', import.meta.url)),
  resolve: { alias: { '@': fileURLToPath(new URL('../../../src', import.meta.url)) } },
  plugins: [{ name: 'temporary-certificate', closeBundle() { rmSync(directory, { recursive: true, force: true }) } }],
  // WebKit reaches the server through intercepted requests, which carry no WebSocket; no page reloads anyway.
  server: { host: '127.0.0.1', port: 5188, strictPort: true, allowedHosts: ['typography.baander.app'], hmr: false,
    https: { key: readFileSync(key), cert: readFileSync(cert) },
    fs: { allow: [fileURLToPath(new URL('../../..', import.meta.url))] } },
})
