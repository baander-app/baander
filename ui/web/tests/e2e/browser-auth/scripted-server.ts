/**
 * The scripted server behind the browser-auth suite: one loopback HTTP server that answers for the
 * web origin (the fixture page and the service worker script), the API origin (login, logout,
 * refresh, images and streams) and a foreign origin that shares the image paths. The three hosts
 * are given at start: the named baander.app hosts a Chromium flag resolves to the loopback, or the
 * loopback names for a browser without such a flag.
 *
 * The refresh endpoint answers from the refresh state machine in ./refresh-state.ts, which mirrors
 * the integration matrix. Its answers take the shapes `/api/auth/refresh` gives: a 200 with the pair
 * under `data`, a 401 ApiError, a 400 `use_dpop_nonce` challenge with a `DPoP-Nonce` header, and a
 * 503 ApiError with `Retry-After` and `Cache-Control: no-store` and no nonce header. The logout
 * endpoint answers 200 for a live access token, 401 for an unknown or revoked one, and the same
 * 503 as the refresh during an outage.
 *
 * Modes script the server-side faults: `nonce-challenge` challenges a proof that carries no issued
 * nonce; `outage` answers refresh and logout 503 until the mode ends; `lose-next-response` rotates
 * the token and then drops the connection once, so the client's transport retry presents the
 * consumed token.
 */

import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { transformSync } from 'esbuild'
import type { FixtureBundles, StoreKind } from './fixture-bundles'
import { RefreshState } from './refresh-state'

export type ServerMode = 'normal' | 'nonce-challenge' | 'outage' | 'lose-next-response'

/** The hosts one scripted server answers for; a site without a foreign host serves no foreign origin. */
export interface SiteHosts {
  web: string
  api: string
  foreign: string | null
}

/** The named hosts; Chromium resolves them to the loopback through a flag. */
export const NAMED_HOSTS: SiteHosts = { web: 'web.baander.app', api: 'api.baander.app', foreign: 'foreign.baander.app' }

/**
 * Two loopback names for a browser that cannot map a host name: `localhost` and `127.0.0.1` are
 * distinct origins and both secure contexts, so the key store and the service worker work.
 */
export const LOOPBACK_HOSTS: SiteHosts = { web: 'localhost', api: '127.0.0.1', foreign: null }

export interface Observation {
  host: string
  path: string
  method: string
  authorization: string | null
  proof: string | null
  cookie: string | null
  range: string | null
}

export interface RefreshObservation {
  token: string
  key: string | null
  nonce: string | null
  status: number | 'lost'
}

export interface AuthCall {
  path: string
  authorization: string | null
  status: number
}

interface Gate {
  wait: Promise<void>
  release: () => void
}

export interface ScriptedServer {
  webOrigin: string
  apiOrigin: string
  foreignOrigin: string | null
  /** Which page script the web origin serves; a page that navigates keeps getting the same one. */
  store: StoreKind
  mode: ServerMode
  refreshState: RefreshState
  observations: Observation[]
  refreshes: RefreshObservation[]
  authCalls: AuthCall[]
  gates: Map<string, Gate>
  /** A fresh refresh state, empty logs and the normal mode, for the next test. */
  reset(store: StoreKind): void
  close(): Promise<void>
}

const RETRY_AFTER_SECONDS = 5

const workerPath = fileURLToPath(new URL('../../../src/features/player/services/auth-stream-worker.ts', import.meta.url))
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYZkAAAAASUVORK5CYII=', 'base64')

interface ParsedProof {
  key: string | null
  nonce: string | null
}

/** The fake signer writes JSON; the initial access token's fixed proof carries neither field. */
function parseProof(header: string | string[] | undefined): ParsedProof {
  if (typeof header !== 'string') return { key: null, nonce: null }
  try {
    const proof = JSON.parse(header) as { key?: unknown; nonce?: unknown }
    return {
      key: typeof proof.key === 'string' ? proof.key : null,
      nonce: typeof proof.nonce === 'string' ? proof.nonce : null,
    }
  } catch {
    return { key: null, nonce: null }
  }
}

function headerValue(header: string | string[] | undefined): string | null {
  return typeof header === 'string' ? header : null
}

function bearerToken(authorization: string | null): string | null {
  return authorization?.startsWith('DPoP ') ? authorization.slice('DPoP '.length) : null
}

function apiError(message: string, code: number): string {
  return JSON.stringify({ error: { message, code } })
}

function readBody(request: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    let body = ''
    request.on('data', chunk => { body += String(chunk) })
    request.on('end', () => resolve(body))
    request.on('error', reject)
  })
}

function sendJson(response: ServerResponse, status: number, body: string, headers: Record<string, string> = {}): void {
  response.writeHead(status, { 'Content-Type': 'application/json', ...headers })
  response.end(body)
}

/** The outage answer of the refresh and logout routes: 503 with Retry-After and no nonce. */
function sendOutage(response: ServerResponse): void {
  sendJson(response, 503, apiError('The nonce store is unavailable', 503), {
    'Retry-After': String(RETRY_AFTER_SECONDS),
    'Cache-Control': 'no-store',
  })
}

function fixtureHtml(apiOrigin: string): string {
  return [
    '<!doctype html>',
    '<title>Worker transport fixture</title>',
    `<script>window.__BAANDER_API_URL__ = ${JSON.stringify(apiOrigin)}</script>`,
    '<script src="/fixture.js"></script>',
  ].join('')
}

export async function startScriptedServer(bundles: FixtureBundles, hosts: SiteHosts = NAMED_HOSTS): Promise<ScriptedServer> {
  const worker = transformSync(readFileSync(workerPath, 'utf8'), { loader: 'ts' }).code
  const knownHosts = [hosts.web, hosts.api, hosts.foreign].filter((host): host is string => host !== null)
  let nonces = new Set<string>()
  let issuedNonces = 0

  const issueNonce = (): string => {
    const nonce = `nonce-${++issuedNonces}`
    nonces.add(nonce)
    return nonce
  }

  const answerRefresh = async (request: IncomingMessage, response: ServerResponse): Promise<void> => {
    const { refreshToken } = JSON.parse(await readBody(request)) as { refreshToken: string }
    const proof = parseProof(request.headers.dpop)
    // The presentation is recorded before a gate holds it, so a test can act while it is pending.
    const record: RefreshObservation = { token: refreshToken, key: proof.key, nonce: proof.nonce, status: 0 }
    scripted.refreshes.push(record)
    await scripted.gates.get(refreshToken)?.wait

    if (scripted.mode === 'outage') {
      record.status = 503
      sendOutage(response)
      return
    }
    if (scripted.mode === 'nonce-challenge' && !(proof.nonce !== null && nonces.delete(proof.nonce))) {
      record.status = 400
      sendJson(response, 400, JSON.stringify({
        error: 'use_dpop_nonce',
        error_description: 'Authorization server requires nonce in DPoP proof.',
      }), { 'DPoP-Nonce': issueNonce() })
      return
    }

    const verdict = scripted.refreshState.present(refreshToken, proof.key)
    if (scripted.mode === 'lose-next-response') {
      // The token was rotated; the answer never leaves the server.
      scripted.mode = 'normal'
      record.status = 'lost'
      request.socket.destroy()
      return
    }
    record.status = verdict.status
    if (verdict.status === 200) {
      sendJson(response, 200, JSON.stringify({ data: { ...verdict.pair, tokenType: 'Bearer', expiresIn: 3600 } }), {
        'DPoP-Nonce': issueNonce(),
      })
      return
    }
    sendJson(response, 401, apiError(`The refresh token was rejected: ${verdict.reason}`, 401))
  }

  const answerLogin = async (request: IncomingMessage, response: ServerResponse): Promise<void> => {
    const { email } = JSON.parse(await readBody(request)) as { email: string }
    const proof = parseProof(request.headers.dpop)
    const root = email.split('@')[0]
    const pair = scripted.refreshState.login(root, proof.key ?? 'no-key')
    scripted.authCalls.push({ path: '/api/auth/login', authorization: null, status: 200 })
    sendJson(response, 200, JSON.stringify({
      data: {
        ...pair,
        tokenType: 'Bearer',
        expiresIn: 3600,
        user: { uuid: `uuid-${root}`, email, publicId: `usr_${root}`, name: null, roles: ['ROLE_USER'] },
      },
    }), { 'DPoP-Nonce': issueNonce() })
  }

  const answerLogout = (request: IncomingMessage, response: ServerResponse): void => {
    const authorization = headerValue(request.headers.authorization)
    const token = bearerToken(authorization)
    if (scripted.mode === 'outage') {
      scripted.authCalls.push({ path: '/api/auth/logout', authorization, status: 503 })
      sendOutage(response)
      return
    }
    const verdict = token === null ? { status: 401, reason: 'missing' } as const : scripted.refreshState.logout(token)
    scripted.authCalls.push({ path: '/api/auth/logout', authorization, status: verdict.status })
    if (verdict.status === 200) {
      sendJson(response, 200, JSON.stringify({ data: { message: 'Token revoked.' } }))
      return
    }
    sendJson(response, 401, apiError(`Not authenticated: the access token is ${verdict.reason}`, 401))
  }

  const answerMedia = (request: IncomingMessage, response: ServerResponse, host: string, path: string): void => {
    const authorization = headerValue(request.headers.authorization)
    const proof = headerValue(request.headers.dpop)
    scripted.observations.push({
      host,
      path,
      method: request.method ?? '',
      authorization,
      proof,
      cookie: request.headers.cookie ?? null,
      range: request.headers.range ?? null,
    })
    if (host !== hosts.foreign) {
      if (authorization === null || proof === null) {
        response.writeHead(401)
        response.end('Worker authentication missing')
        return
      }
      const tokenStatus = scripted.refreshState.accessTokenStatus(bearerToken(authorization) ?? '')
      if (tokenStatus === 'unknown' || tokenStatus === 'revoked') {
        response.writeHead(401)
        response.end(tokenStatus)
        return
      }
      // The pair a login issued counts as expired here; only a rotation's access token is accepted.
      if (path.endsWith('/always401') || (path.endsWith('/expired') && tokenStatus !== 'rotated')) {
        response.writeHead(401)
        response.end('expired')
        return
      }
    }
    if (path === '/api/images/ready') {
      response.writeHead(200)
      response.end('ready')
      return
    }
    if (path === '/api/images/redirect') {
      response.writeHead(302, { Location: `${scripted.foreignOrigin}/api/images/redirect-target` })
      response.end()
      return
    }
    if (path.startsWith('/api/stream/')) {
      const ranged = request.headers.range === 'bytes=0-3'
      response.writeHead(ranged ? 206 : 200, ranged ? { 'Content-Range': 'bytes 0-3/8' } : {})
      response.end(request.method === 'HEAD' ? undefined : ranged ? 'abcd' : 'abcdefgh')
      return
    }
    response.writeHead(200, { 'Content-Type': 'image/png' })
    response.end(pixel)
  }

  const handle = (request: IncomingMessage, response: ServerResponse): void => {
    const host = (request.headers.host ?? '').split(':')[0]
    if (!knownHosts.includes(host)) {
      response.writeHead(400)
      response.end()
      return
    }
    const path = new URL(request.url ?? '/', 'http://web.baander.app').pathname
    response.setHeader('Access-Control-Allow-Origin', scripted.webOrigin)
    response.setHeader('Access-Control-Allow-Credentials', 'true')
    response.setHeader('Access-Control-Allow-Headers', 'Authorization, DPoP, Range, Content-Type, X-Requested-With')
    response.setHeader('Access-Control-Allow-Methods', 'GET, HEAD, POST, OPTIONS')
    response.setHeader('Access-Control-Expose-Headers', 'Content-Range, DPoP-Nonce, Retry-After, Cache-Control')
    if (request.method === 'OPTIONS') {
      response.writeHead(204)
      response.end()
      return
    }
    if (path === '/fixture.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript' })
      response.end(bundles[scripted.store])
      return
    }
    if (path === '/auth-stream-worker.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript', 'Service-Worker-Allowed': '/' })
      response.end(worker)
      return
    }
    if (path === '/seed-cookie') {
      response.writeHead(200, { 'Set-Cookie': 'worker-fixture=must-not-forward; Path=/; SameSite=Lax' })
      response.end('seeded')
      return
    }
    if (path === '/api/auth/refresh') {
      answerRefresh(request, response).catch(error => {
        console.error('The scripted refresh failed', error)
        sendJson(response, 500, apiError('Scripted refresh failed', 500))
      })
      return
    }
    if (path === '/api/auth/login') {
      answerLogin(request, response).catch(error => {
        console.error('The scripted login failed', error)
        sendJson(response, 500, apiError('Scripted login failed', 500))
      })
      return
    }
    if (path === '/api/auth/logout') {
      answerLogout(request, response)
      return
    }
    if (path.startsWith('/api/images/') || path.startsWith('/api/stream/')) {
      answerMedia(request, response, host, path)
      return
    }
    response.writeHead(200, { 'Content-Type': 'text/html' })
    response.end(fixtureHtml(scripted.apiOrigin))
  }

  const server: Server = createServer(handle)
  const scripted: ScriptedServer = {
    webOrigin: '',
    apiOrigin: '',
    foreignOrigin: null,
    store: 'fake',
    mode: 'normal',
    refreshState: new RefreshState(),
    observations: [],
    refreshes: [],
    authCalls: [],
    gates: new Map(),
    reset(store) {
      scripted.store = store
      scripted.mode = 'normal'
      scripted.refreshState = new RefreshState()
      scripted.observations.length = 0
      scripted.refreshes.length = 0
      scripted.authCalls.length = 0
      scripted.gates.forEach(gate => gate.release())
      scripted.gates.clear()
      nonces = new Set()
      issuedNonces = 0
    },
    async close() {
      scripted.gates.forEach(gate => gate.release())
      server.closeAllConnections()
      await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
    },
  }

  await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
  const address = server.address()
  if (!address || typeof address === 'string') throw new Error('Loopback fixture did not start')
  scripted.webOrigin = `http://${hosts.web}:${address.port}`
  scripted.apiOrigin = `http://${hosts.api}:${address.port}`
  scripted.foreignOrigin = hosts.foreign === null ? null : `http://${hosts.foreign}:${address.port}`
  return scripted
}
