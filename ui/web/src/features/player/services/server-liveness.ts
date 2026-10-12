import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/**
 * The server's public liveness endpoint. It answers 200 whenever a worker can respond, and its
 * body says `"draining": true` while the server finishes its work before it stops.
 */
const LIVE_PATH = '/live'
const PROBE_TIMEOUT_MS = 5_000

export type ServerLiveness = 'up' | 'down'

interface LivenessBody {
  draining?: unknown
}

/**
 * A media element hides the HTTP status of a failed stream, so the player asks the server
 * directly. Only a 2xx answer from a server that is not draining counts as up: no answer, a
 * timeout, nginx's 502 while the web container restarts, and a draining server that still
 * answers on a kept-alive connection all mean the stream cannot be requested again yet.
 */
export async function probeServerLiveness(): Promise<ServerLiveness> {
  try {
    const response = await AXIOS_INSTANCE.get<LivenessBody | undefined>(LIVE_PATH, {
      timeout: PROBE_TIMEOUT_MS,
      validateStatus: () => true,
    })
    if (response.status < 200 || response.status >= 300) return 'down'
    return response.data?.draining === true ? 'down' : 'up'
  } catch {
    return 'down'
  }
}
