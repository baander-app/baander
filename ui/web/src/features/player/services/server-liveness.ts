import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/** The server's public liveness endpoint; it answers 200 whenever a worker can respond. */
const LIVE_PATH = '/live'
const PROBE_TIMEOUT_MS = 5_000

export type ServerLiveness = 'up' | 'down'

/**
 * A media element hides the HTTP status of a failed stream, so the player asks the server
 * directly. Only a 2xx answer counts as up: no answer, a timeout, and nginx's 502 while the
 * web container restarts all mean the server cannot be reached.
 */
export async function probeServerLiveness(): Promise<ServerLiveness> {
  try {
    const response = await AXIOS_INSTANCE.get(LIVE_PATH, {
      timeout: PROBE_TIMEOUT_MS,
      validateStatus: () => true,
    })
    return response.status >= 200 && response.status < 300 ? 'up' : 'down'
  } catch {
    return 'down'
  }
}
