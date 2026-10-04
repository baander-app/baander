import { test as base, expect } from '@playwright/test'
import { build } from 'esbuild'
import { createServer } from 'node:https'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { RenderOptions, LifecycleCase, LifecycleResult, PassiveAnalysisResult, StereoAnalysisOptions, StereoAnalysisResult } from './browser-fixture'

export interface Signal { left: number[]; right: number[]; leftGain: number; rightGain: number }
export const test = base.extend<{ render: (options: RenderOptions) => Promise<Signal>; lifecycle: (scenario: LifecycleCase) => Promise<LifecycleResult>; passiveAnalysis: () => Promise<PassiveAnalysisResult>; stereoAnalysis: (options: StereoAnalysisOptions) => Promise<StereoAnalysisResult> }, { origin: string }>({
  origin: [async ({ browserName }, provide) => {
    if (browserName !== 'chromium') throw new Error('The audio regression harness requires Chromium')
    const temporary = mkdtempSync(resolve(tmpdir(), 'baander-audio-graph-'))
    let server: ReturnType<typeof createServer> | undefined
    try {
      const key = resolve(temporary, 'key.pem'), cert = resolve(temporary, 'cert.pem')
      execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key,
        '-out', cert, '-days', '1', '-subj', '/CN=audio.baander.app'], { stdio: 'ignore' })
      const sourceRoot = fileURLToPath(new URL('../../../src/', import.meta.url))
      const bundle = await build({ bundle: true, write: false, platform: 'browser', format: 'iife',
        entryPoints: [fileURLToPath(new URL('./browser-fixture.ts', import.meta.url))],
        absWorkingDir: resolve(sourceRoot, '..'),
        plugins: [{ name: 'external-analysis-fixtures', setup(plugin) {
          plugin.onResolve({ filter: /^\.\/wasm-loader$/ }, () => ({ path: 'wasm-loader', namespace: 'fixture' }))
          plugin.onLoad({ filter: /.*/, namespace: 'fixture' }, () => ({ contents: `
            const api = new Proxy({}, { get: () => () => {} });
            export const getLoudness=async()=>api, getDynamics=async()=>api, getSpectralFeatures=async()=>api;
            export const getWasmUrl=file=>location.pathname.startsWith('/real/')?'/dsp/'+file:'/analysis.wasm';
            export const getAudioWorkletUrl=file=>location.pathname.startsWith('/real/')?'/real/'+file:'/'+file;
          ` }))
        } }],
      })
      const wasmLoaderBundle = await build({ bundle: true, write: false, platform: 'browser', format: 'esm',
        entryPoints: [resolve(sourceRoot, 'features/player/services/wasm-loader.ts')],
      })
      const dspDirectory = fileURLToPath(new URL('../../../../../public/dsp/', import.meta.url))
      server = createServer({ key: readFileSync(key), cert: readFileSync(cert) }, (request, response) => {
        if (request.url === '/wasm-loader.js') {
          response.setHeader('Content-Type', 'application/javascript')
          response.end(wasmLoaderBundle.outputFiles[0].text)
        } else if (/^\/dsp\/(loudness_r128|dynamics_meter|spectral_features|fft2048)\.wasm$/.test(request.url ?? '')) {
          response.setHeader('Content-Type', 'application/wasm')
          response.end(readFileSync(resolve(dspDirectory, request.url!.slice('/dsp/'.length))))
        } else if (request.url === '/real/magic-soup-processor.js' || request.url === '/real/wasm-spectrum.js') {
          response.setHeader('Content-Type', 'application/javascript')
          response.end(readFileSync(resolve(dspDirectory, '../audio-worklets', request.url.slice('/real/'.length))))
        } else if (request.url === '/fixture.js') {
          response.setHeader('Content-Type', 'application/javascript')
          response.end('window.Worker=class { postMessage(){} terminate(){} };\n' + bundle.outputFiles[0].text)
        } else if (request.url === '/magic-soup-processor.js' || request.url === '/wasm-spectrum.js') {
          response.setHeader('Content-Type', 'application/javascript')
          response.end(`
            class FixtureAnalysis extends AudioWorkletProcessor {
              constructor() {
                super();
                this.port.onmessage = event => {
                  if (event.data.type === 'wasm') this.port.postMessage({type:'ready'});
                };
              }
              process(inputs, outputs) {
                for (let channel=0; channel<outputs[0].length; channel++) {
                  if (inputs[0]?.[channel]) outputs[0][channel].set(inputs[0][channel]);
                }
                return true;
              }
            }
            registerProcessor('${request.url === '/wasm-spectrum.js' ? 'wasm-spectrum' : 'magic-soup-processor'}', FixtureAnalysis);
          `)
        } else if (request.url === '/analysis.wasm') response.end('')
        else response.end('<!doctype html><title>Native audio graph regression</title><script src="/fixture.js"></script>')
      })
      await new Promise<void>((resolve, reject) => { server!.once('error', reject); server!.listen(0, '127.0.0.1', resolve) })
      const address = server.address()
      if (!address || typeof address === 'string') throw new Error('Missing loopback fixture address')
      await provide(`https://audio.baander.app:${address.port}`)
    } finally {
      if (server?.listening) await new Promise<void>(resolve => server!.close(() => resolve()))
      rmSync(temporary, { recursive: true })
    }
  }, { scope: 'worker' }],
  browser: async ({ playwright }, provide) => {
    const browser = await playwright.chromium.launch({ args: ['--host-resolver-rules=MAP audio.baander.app 127.0.0.1', '--no-proxy-server', '--autoplay-policy=no-user-gesture-required',
      ...(process.env.AUDIO_GRAPH_CDP_PORT ? [`--remote-debugging-port=${process.env.AUDIO_GRAPH_CDP_PORT}`] : [])] })
    try { await provide(browser) } finally { await browser.close() }
  },
  stereoAnalysis: async ({ page, origin }, provide) => {
    await provide(async options => {
      await page.goto(origin + (options.mode === 'worklet' ? '/real/' : '/'))
      await page.waitForFunction(() => 'audioGraphFixture' in window)
      return page.evaluate(options => (window as unknown as {
        audioGraphFixture: { stereoAnalysis(options: StereoAnalysisOptions): Promise<StereoAnalysisResult> }
      }).audioGraphFixture.stereoAnalysis(options), options)
    })
  },
  context: async ({ browser }, provide) => {
    const context = await browser.newContext({ ignoreHTTPSErrors: true })
    try { await provide(context) } finally { await context.close() }
  },
  passiveAnalysis: async ({ page, origin }, provide) => {
    await page.goto(origin)
    await page.waitForFunction(() => 'audioGraphFixture' in window)
    await provide(() => page.evaluate(() => (window as unknown as {
      audioGraphFixture: { passiveAnalysis(): Promise<PassiveAnalysisResult> }
    }).audioGraphFixture.passiveAnalysis()))
  },
  lifecycle: async ({ page, origin }, provide) => {
    await page.goto(origin)
    await page.waitForFunction(() => 'audioGraphFixture' in window)
    await provide(scenario => page.evaluate(scenario => (window as unknown as {
      audioGraphFixture: { lifecycle(scenario: LifecycleCase): Promise<LifecycleResult> }
    }).audioGraphFixture.lifecycle(scenario), scenario))
  },
  render: async ({ page, origin }, provide) => {
    await page.goto(origin)
    await page.waitForFunction(() => 'audioGraphFixture' in window)
    await provide(options => page.evaluate(options => (window as unknown as {
      audioGraphFixture: { render(options: RenderOptions): Promise<Signal> }
    }).audioGraphFixture.render(options), options))
  },
})

export { expect }
