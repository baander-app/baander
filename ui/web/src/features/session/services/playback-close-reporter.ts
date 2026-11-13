import type { PlaybackEndpointResource, PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { onAccessTokenChange, prepareKeepalivePost, sendKeepalivePost, type PreparedKeepaliveRequest } from '@/shared/api-client/keepalive-request'
import type { PlaybackRecoveryCheckpoint } from './playback-recovery-store'

export const CLOSE_REPORT_PATH = '/api/session/close'

/** What the close reporter reads from its page at the moment it needs it. */
export interface ClosingPage {
  /** Whether the page still belongs to the account and runtime it registered for. */
  current(): boolean
  endpoint(): PlaybackEndpointResource | null
  snapshot(): PlaybackSnapshotResource | null
  /** Where the page's player stands, or the snapshot's cursor while it holds none. */
  cursor(snapshot: PlaybackSnapshotResource): number
  checkpoint(snapshot: PlaybackSnapshotResource, position: number): PlaybackRecoveryCheckpoint
}

/**
 * Keeps the close report of a page signed for its current registration and sends it as the page
 * unloads: a proof cannot be signed while unloading.
 */
export class PlaybackCloseReporter {
  private readonly page: ClosingPage
  private prepared: { endpoint: PlaybackEndpointResource; request: PreparedKeepaliveRequest } | null = null
  private preparation = 0

  constructor(page: ClosingPage) {
    this.page = page
  }

  /** Signs the report again whenever the access token is replaced. Returns the unsubscription. */
  start(): () => void {
    return onAccessTokenChange(() => this.prepare())
  }

  /** Signs the close report the page would send if it went away now. */
  prepare(): void {
    const endpoint = this.page.endpoint()
    if (!endpoint?.capability || !this.page.current()) return
    const preparation = ++this.preparation
    prepareKeepalivePost(CLOSE_REPORT_PATH, { 'X-Baander-Playback-Endpoint': endpoint.capability }).then(request => {
      if (preparation !== this.preparation || endpoint !== this.page.endpoint()) return
      this.prepared = request ? { endpoint, request } : null
    }).catch(error => {
      console.warn('[PlaybackSession] A close report could not be prepared:', error instanceof Error ? error.message : error)
    })
  }

  /** Drops the prepared report and any preparation still being signed. */
  discard(): void {
    this.prepared = null
    this.preparation++
  }

  /** Sends the prepared report of a page that is going away, with the cursor its player stands at. */
  send(): void {
    const endpoint = this.page.endpoint()
    const snapshot = this.page.snapshot()
    const prepared = this.prepared
    if (!endpoint || !snapshot || !prepared || prepared.endpoint !== endpoint || !this.page.current()) return
    this.prepared = null
    const position = this.page.cursor(snapshot)
    sendKeepalivePost(prepared.request, { incarnationId: endpoint.incarnationId, checkpoint: this.page.checkpoint(snapshot, position) })
  }
}
