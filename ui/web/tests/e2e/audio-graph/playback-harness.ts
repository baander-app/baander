import { test as base, expect } from '@playwright/test'
import { build } from 'esbuild'
import { createServer } from 'node:https'
import type { ServerResponse } from 'node:http'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'

function wave(seconds: number, frequency: number) {
  const rate = 8000, samples = seconds * rate
  const result = Buffer.alloc(44 + samples * 2)
  result.write('RIFF', 0); result.writeUInt32LE(result.length - 8, 4); result.write('WAVEfmt ', 8)
  result.writeUInt32LE(16, 16); result.writeUInt16LE(1, 20); result.writeUInt16LE(1, 22)
  result.writeUInt32LE(rate, 24); result.writeUInt32LE(rate * 2, 28); result.writeUInt16LE(2, 32); result.writeUInt16LE(16, 34)
  result.write('data', 36); result.writeUInt32LE(samples * 2, 40)
  for (let i = 0; i < samples; i++) result.writeInt16LE(Math.round(6000 * Math.sin(2 * Math.PI * frequency * i / rate)), 44 + i * 2)
  return result
}
export const test = base.extend<Record<never, never>, { origin: string }>({
  origin: [async ({ browserName }, provide) => {
    if (browserName !== 'chromium') throw new Error('Native media regression requires Chromium')
    const temporary = mkdtempSync(resolve(tmpdir(), 'baander-media-'))
    let server: ReturnType<typeof createServer> | undefined
    try {
      const key = resolve(temporary, 'key.pem'), cert = resolve(temporary, 'cert.pem')
      execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert, '-days', '1', '-subj', '/CN=audio.baander.app'], { stdio: 'ignore' })
      const webRoot = fileURLToPath(new URL('../../../', import.meta.url))
      let sourceRoot = resolve(webRoot, 'src')
      if (process.env.AUDIO_GRAPH_SOURCE_REF) {
        const archive = execFileSync('git', ['archive', process.env.AUDIO_GRAPH_SOURCE_REF, 'ui/web/src'], { cwd: resolve(webRoot, '../..'), maxBuffer: 32 * 1024 * 1024 })
        execFileSync('tar', ['-x', '-C', temporary], { input: archive })
        sourceRoot = resolve(temporary, 'ui/web/src')
      }
      const require = createRequire(import.meta.url)
      const bundle = await build({ bundle: true, write: false, platform: 'browser', format: 'iife',
        entryPoints: [fileURLToPath(new URL('./playback-fixture.tsx', import.meta.url))], absWorkingDir: webRoot,
        alias: { '@': sourceRoot }, nodePaths: [resolve(webRoot, 'node_modules')],
        plugins: [{ name: 'external-services', setup(plugin) {
          plugin.onResolve({ filter: /^(?:react(?:-dom)?|zustand|scheduler|sonner)(?:\/.*)?$/ }, args => ({ path: require.resolve(args.path) }))
          plugin.onResolve({ filter: /(?:activity-service|eq-reapply|wasm-loader|server-liveness)$/ }, args => ({ path: args.path, namespace: 'fixture' }))
          plugin.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({ contents: args.path.endsWith('activity-service')
            ? 'export const activityService={recordPlay(payload){(globalThis.playbackActivity??=[]).push(payload.songId)}}'
            : args.path.endsWith('eq-reapply') ? 'export function reapplyAllEqState(){}'
            // The app probes through its shared API client; the fixture asks the same endpoint with the same rule.
            : args.path.endsWith('server-liveness') ? "export async function probeServerLiveness(){try{const response=await fetch('/live',{cache:'no-store'});if(!response.ok)return 'down';const body=await response.json().catch(()=>null);return body?.draining===true?'down':'up'}catch{return 'down'}}"
            : `const api=new Proxy({}, {get:()=>()=>{}}); export const getLoudness=async()=>api,getDynamics=async()=>api,getSpectralFeatures=async()=>api; export const getWasmUrl=()=>'/analysis.wasm',getAudioWorkletUrl=file=>'/'+file;` }))
        } }],
      })
      const waves = [wave(4, 440), wave(5, 660), wave(6, 880)]
      // A longer track: its first three seconds at once, so playback starts promptly, then the rest
      // at 1.25x real time, so a cut finds much of it unbuffered.
      const long = wave(20, 330)
      const slowStreams = new Set<ServerResponse>()
      // While down, the fixture answers liveness and streams as nginx does for a stopped web container.
      let down = false
      const sendSlowly = (response: ServerResponse, start: number, end: number) => {
        let offset = Math.min(end + 1, start + 96_000)
        response.write(long.subarray(start, offset))
        slowStreams.add(response)
        const timer = setInterval(() => {
          const next = Math.min(end + 1, offset + 5000)
          response.write(long.subarray(offset, next))
          offset = next
          if (offset > end) {
            clearInterval(timer)
            slowStreams.delete(response)
            response.end()
          }
        }, 250)
        response.on('close', () => {
          clearInterval(timer)
          slowStreams.delete(response)
        })
      }
      server = createServer({ key: readFileSync(key), cert: readFileSync(cert) }, (request, response) => {
        const url = new URL(request.url!, 'https://audio.baander.app')
        if (url.pathname === '/fixture/server') {
          down = url.searchParams.get('state') === 'down'
          // A stopping server cuts its streams without a terminating chunk.
          if (down) for (const stream of slowStreams) stream.socket?.destroy()
          response.end()
        } else if (url.pathname === '/live') {
          if (down) { response.writeHead(502); response.end(); return }
          response.setHeader('Content-Type', 'application/json')
          response.end('{"status":"alive","draining":false}')
        } else if (url.pathname === '/api/stream/track' && url.searchParams.get('id') === 'long') {
          if (down) { response.writeHead(502); response.end(); return }
          response.setHeader('Content-Type', 'audio/wav'); response.setHeader('Accept-Ranges', 'bytes')
          const range = request.headers.range?.match(/bytes=(\d+)-(\d*)/)
          const start = range ? Number(range[1]) : 0, end = range?.[2] ? Number(range[2]) : long.length - 1
          if (range) response.writeHead(206, { 'Content-Range': `bytes ${start}-${end}/${long.length}`, 'Content-Length': end - start + 1 })
          else response.setHeader('Content-Length', long.length)
          sendSlowly(response, start, end)
        } else if (url.pathname === '/fixture.js') {
          response.setHeader('Content-Type', 'application/javascript')
          response.end('window.Worker=class {postMessage(){} terminate(){}};\n' + bundle.outputFiles[0].text)
        } else if (url.pathname === '/api/stream/track') {
          const data = waves[['first', 'second', 'third'].indexOf(url.searchParams.get('id')!)]
          if (!data) { response.writeHead(404); response.end(); return }
          response.setHeader('Content-Type', 'audio/wav'); response.setHeader('Accept-Ranges', 'bytes')
          const range = request.headers.range?.match(/bytes=(\d+)-(\d*)/)
          if (range) {
            const start = Number(range[1]), end = range[2] ? Number(range[2]) : data.length - 1
            response.writeHead(206, { 'Content-Range': `bytes ${start}-${end}/${data.length}`, 'Content-Length': end - start + 1 })
            response.end(data.subarray(start, end + 1))
          } else { response.setHeader('Content-Length', data.length); response.end(data) }
        } else if (url.pathname.endsWith('.js')) {
          response.setHeader('Content-Type', 'application/javascript')
          response.end(`class Analysis extends AudioWorkletProcessor {constructor(){super();this.port.onmessage=()=>this.port.postMessage({type:'ready'})}process(){return true}}registerProcessor('${url.pathname.includes('spectrum') ? 'wasm-spectrum' : 'magic-soup-processor'}',Analysis)`)
        } else if (url.pathname.endsWith('.wasm')) response.end('')
        else response.end('<!doctype html><title>Native playback regression</title><div id="root"></div><script src="/fixture.js"></script>')
      })
      await new Promise<void>((resolve, reject) => { server!.once('error', reject); server!.listen(0, '127.0.0.1', resolve) })
      const address = server.address()
      if (!address || typeof address === 'string') throw new Error('Missing local address')
      await provide(`https://audio.baander.app:${address.port}`)
    } finally {
      if (server?.listening) await new Promise<void>(resolve => server!.close(() => resolve()))
      rmSync(temporary, { recursive: true })
    }
  }, { scope: 'worker' }],
  browser: async ({ playwright }, provide) => {
    const browser = await playwright.chromium.launch({ args: ['--host-resolver-rules=MAP audio.baander.app 127.0.0.1', '--no-proxy-server', '--autoplay-policy=no-user-gesture-required'] })
    try { await provide(browser) } finally { await browser.close() }
  },
  context: async ({ browser }, provide) => {
    const context = await browser.newContext({ ignoreHTTPSErrors: true })
    try { await provide(context) } finally { await context.close() }
  },
})
export { expect }
