import { expect, type Page, type TestInfo } from '@playwright/test'
import type { PlaybackExecutionAdapter } from '../../../src/features/session/services/playback-execution'
import type { PlaybackSnapshotResource } from '../../../src/shared/api-client/gen/endpoints'
import { loadedRuntimeModule } from './loaded-runtime-module'

interface DiagnosticEvent {
  at: number
  browser: string
  kind: string
  [key: string]: unknown
}

export class VideoDiagnostics {
  private readonly http: DiagnosticEvent[] = []
  private readonly pages: { page: Page; name: string }[] = []

  observe(page: Page, name: string) {
    this.pages.push({ page, name })
    const relevant = (path: string) => /^\/(?:api\/(?:session\/|stream\/|transcode\/|movies\/)|src\/|node_modules\/|@)/.test(path) || /\.(?:js|m3u8|m4s|ts)$/.test(path)
    const record = (event: Omit<DiagnosticEvent, 'at' | 'browser'>) => {
      this.http.push({ at: Date.now(), browser: name, ...event } as DiagnosticEvent)
      if (this.http.length > 2000) this.http.shift()
    }
    page.on('requestfailed', request => {
      const path = new URL(request.url()).pathname
      if (relevant(path)) record({ kind: 'http-failed', path, method: request.method(),
        failure: request.failure()?.errorText.match(/^net::[A-Z_]+$/)?.[0] ?? 'request failed' })
    })
    page.on('response', async response => {
      const path = new URL(response.url()).pathname
      if (!relevant(path)) return
      record({ kind: 'http', path, method: response.request().method(), status: response.status() })
      try {
        if (path.startsWith('/api/session/')) {
          const body = await response.json() as { data?: PlaybackSnapshotResource } & Partial<PlaybackSnapshotResource>
          const state = body.data ?? body
          record({ kind: 'session-state', path, revision: state.revision, desired: state.desiredState,
            observed: state.observedState, playback: state.playbackState, phase: state.transfer?.state ?? null,
            position: state.position, activeIncarnationId: state.activeIncarnationId,
            outcome: state.requestResult?.outcome ?? null,
            commands: state.commands?.map(command => ({ type: command.type, revision: command.revision })) })
        } else if (path === '/api/stream/sign') {
          interface SourceState { url?: string | null; ready?: boolean; generation?: number; revision?: number; position?: number; startTime?: number; status?: string }
          const body = await response.json() as SourceState & { data?: SourceState }
          const state = body.data ?? body
          record({ kind: 'signed-source', ready: state.ready, generation: state.generation, revision: state.revision,
            position: state.position, startTime: state.startTime, status: state.status,
            sourcePath: state.url ? new URL(state.url, new URL(response.url()).origin).pathname : null })
        } else if (path.endsWith('.m3u8')) {
          const body = await response.text()
          record({ kind: 'manifest-content', path, contentType: response.headers()['content-type'],
            hls: body.trimStart().startsWith('#EXTM3U'), html: /^\s*(?:<!doctype html|<html)/i.test(body) })
        }
      } catch {
        // Binary media, empty error responses and closed pages have no JSON observation.
      }
    })
  }

  async instrument(page: Page) {
    await expect.poll(async () => {
      const loadedModule = await loadedRuntimeModule(page, '/src/features/session/services/playback-execution.ts')
      if (!loadedModule) return false
      return page.evaluate(async loadedModule => {
        const { getPlaybackAdapter } = await import(/* @vite-ignore */ loadedModule) as {
          getPlaybackAdapter: (kind: 'video') => PlaybackExecutionAdapter | undefined
        }
        const adapter = getPlaybackAdapter('video')
        if (!adapter) return false
        const state = window as typeof window & { __playbackVideoDiagnostics?: unknown[] }
        if (state.__playbackVideoDiagnostics) return true
        const events: unknown[] = []
        state.__playbackVideoDiagnostics = events
        const native = () => Array.from(document.querySelectorAll('video')).map(video => ({
          paused: video.paused, readyState: video.readyState, networkState: video.networkState,
          currentTime: video.currentTime, duration: Number.isFinite(video.duration) ? video.duration : null,
          seeking: video.seeking, ended: video.ended, errorCode: video.error?.code ?? null, connected: video.isConnected,
        }))
        const record = (method: string, phase: string, detail: object) => {
          events.push({ at: Date.now(), method, phase, ...detail,
            adapterCurrent: getPlaybackAdapter('video') === adapter, native: native() })
          if (events.length > 500) events.shift()
        }
        const caller = () => new Error().stack?.split('\n').filter(line => line.includes('/src/'))
          .map(line => line.replace(/https?:\/\/[^/]+/g, '').replace(/\?[^):\s]+/g, '')).slice(0, 6)
        for (const method of ['pause', 'load'] as const) {
          const original = HTMLMediaElement.prototype[method]
          HTMLMediaElement.prototype[method] = function () {
            if (this instanceof HTMLVideoElement) record('native-call', method, { caller: caller() })
            return original.call(this)
          }
        }
        const source = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'src')
        if (source?.set) {
          const setSource = source.set
          Object.defineProperty(HTMLMediaElement.prototype, 'src', {
            ...source,
            set(value: string) {
              if (this instanceof HTMLVideoElement) record('native-call', 'set-source', {
                empty: value === '', sourcePath: value ? new URL(value, location.href).pathname : null, caller: caller(),
              })
              setSource.call(this, value)
            },
          })
        }
        if (typeof MediaSource !== 'undefined') {
          const addSourceBuffer = MediaSource.prototype.addSourceBuffer
          MediaSource.prototype.addSourceBuffer = function (mime) {
            record('mse', 'add-source-buffer', { mime, readyState: this.readyState })
            try { return addSourceBuffer.call(this, mime) }
            catch (error) {
              record('mse', 'source-buffer-rejected', {
                mime, errorName: error instanceof Error ? error.name : 'Error',
              })
              throw error
            }
          }
        }
        const failure = (error: unknown) => {
          const message = error instanceof Error ? error.message : ''
          return {
            errorName: error instanceof Error && /^[A-Za-z]+Error$/.test(error.name) ? error.name : 'Error',
            reason: /prepared|source.*unavailable/i.test(message) ? 'source-not-ready'
              : /replaced|abort/i.test(message) ? 'ownership-aborted'
                : /play|gesture|autoplay/i.test(message) ? 'native-play'
                  : /seek/i.test(message) ? 'seek'
                    : /volume/i.test(message) ? 'volume' : 'execution-error',
          }
        }
        for (const event of ['loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'playing', 'pause', 'seeking', 'seeked', 'error', 'emptied']) {
          document.addEventListener(event, observed => {
            if (observed.target instanceof HTMLVideoElement) record('native', event, {})
          }, true)
        }
        record('capabilities', 'observed', {
          mediaSource: typeof MediaSource !== 'undefined',
          h264: typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported('video/mp4; codecs="avc1.42E01E"'),
          aac: typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported('audio/mp4; codecs="mp4a.40.2"'),
          nativeHls: document.createElement('video').canPlayType('application/vnd.apple.mpegurl'),
          recoveryEligible: adapter.localRecovery?.eligible ?? false,
        })
        const prepare = adapter.prepare
        adapter.prepare = async function (payload, signal) {
          record('prepare', 'start', { position: payload.position, desiredState: payload.desiredState, aborted: signal.aborted })
          const aborted = () => record('prepare', 'aborted', {})
          signal.addEventListener('abort', aborted, { once: true })
          try {
            const result = await prepare.call(this, payload, signal)
            record('prepare', 'resolved', { aborted: signal.aborted })
            return result
          } catch (error) {
            record('prepare', 'rejected', { ...failure(error), aborted: signal.aborted })
            throw error
          } finally {
            signal.removeEventListener('abort', aborted)
          }
        }
        const execute = adapter.execute
        adapter.execute = async function (command, signal) {
          record('execute', 'start', { type: command.type, revision: command.revision, desiredState: command.payload.desiredState, position: command.payload.position, aborted: signal.aborted })
          try {
            const result = await execute.call(this, command, signal)
            record('execute', 'resolved', { type: command.type, observedState: result.observedState, position: result.position, aborted: signal.aborted })
            return result
          } catch (error) {
            record('execute', 'rejected', { type: command.type, ...failure(error), aborted: signal.aborted })
            throw error
          }
        }
        const quiesce = adapter.quiesce
        adapter.quiesce = async function (signal) {
          record('quiesce', 'start', { aborted: signal.aborted })
          try {
            const result = await quiesce.call(this, signal)
            record('quiesce', 'resolved', { observedState: result.observedState, position: result.position, aborted: signal.aborted })
            return result
          } catch (error) {
            record('quiesce', 'rejected', { ...failure(error), aborted: signal.aborted })
            throw error
          }
        }
        return true
      }, loadedModule)
    }, { timeout: 15_000, message: 'The real video adapter must register before diagnostics wrap it.' }).toBe(true)
  }

  async attach(testInfo: TestInfo, run: string) {
    const browsers = await Promise.all(this.pages.map(async ({ page, name }) => {
      try {
        const [presentationModule, clientModule] = await Promise.all([
          loadedRuntimeModule(page, '/src/features/catalog/stores/video-presentation-store.ts'),
          loadedRuntimeModule(page, '/src/shared/api-client/axios-instance.ts'),
        ])
        if (!presentationModule || !clientModule) throw new Error('The application runtime modules have not loaded.')
        const state = await page.evaluate(async ({ presentationModule, clientModule }) => {
          const { useVideoPresentation } = await import(/* @vite-ignore */ presentationModule) as {
            useVideoPresentation: { getState(): { videoId: string; currentTime: number; pendingSeekPosition: number | null; duration: number; state: string | null } }
          }
          const presentation = useVideoPresentation.getState()
          const { AXIOS_INSTANCE } = await import(/* @vite-ignore */ clientModule) as {
            AXIOS_INSTANCE: { get(path: string): Promise<{ data: { data: PlaybackSnapshotResource } }> }
          }
          const snapshot = (await AXIOS_INSTANCE.get('/api/session/snapshot')).data.data
          return {
            executionModules: performance.getEntriesByType('resource').flatMap(entry => {
              const url = new URL(entry.name)
              if (url.pathname !== '/src/features/session/services/playback-execution.ts') return []
              const timestamp = url.searchParams.get('t')
              return [{ path: url.pathname, timestamp: timestamp && /^\d+$/.test(timestamp) ? timestamp : null }]
            }),
            session: { position: snapshot.position, media: snapshot.media, revision: snapshot.revision,
              activeIncarnationId: snapshot.activeIncarnationId, desiredState: snapshot.desiredState },
            presentation: { videoId: presentation.videoId, currentTime: presentation.currentTime,
              pendingSeekPosition: presentation.pendingSeekPosition, duration: presentation.duration, state: presentation.state },
            sliderPosition: document.querySelector('[aria-label="Seek movie"]')?.getAttribute('aria-valuenow') ?? null,
            status: Array.from(document.querySelectorAll('[role="status"]')).map(element => element.textContent),
            adapter: (window as typeof window & { __playbackVideoDiagnostics?: unknown[] }).__playbackVideoDiagnostics ?? [],
            native: Array.from(document.querySelectorAll('video')).map(video => ({
              paused: video.paused, readyState: video.readyState, networkState: video.networkState,
              currentTime: video.currentTime, duration: Number.isFinite(video.duration) ? video.duration : null,
              seeking: video.seeking, ended: video.ended, errorCode: video.error?.code ?? null,
            })),
          }
        }, { presentationModule, clientModule })
        return { name, ...state }
      } catch {
        return { name, observationUnavailable: true }
      }
    }))
    await testInfo.attach('video-playback-diagnostics', {
      body: JSON.stringify({ run, http: this.http, browsers }, null, 2), contentType: 'application/json',
    })
  }
}
