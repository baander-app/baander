import type { PlaybackEndpointResource } from '@/shared/api-client/gen/endpoints'
import { acquirePlaybackLock, readPlaybackTabId, replacePlaybackTabId, type PlaybackExecutionLock } from './playback-execution-lock'
import { IndexedDbPlaybackRecoveryStorage, type PlaybackRecoveryCheckpoint, type PlaybackRecoveryRecord, type PlaybackRecoveryStorage } from './playback-recovery-store'

export type RecoveryEndpoint = PlaybackEndpointResource

export interface LocalRecoveryState {
  status: 'idle' | 'recovering' | 'ready' | 'recovered' | 'retryable' | 'unavailable'
  message: string | null
}

export class PlaybackLocalRecovery {
  readonly server: string
  readonly account: string
  tabId: string = crypto.randomUUID()
  private readonly storage: PlaybackRecoveryStorage
  private readonly locks = new Map<string, PlaybackExecutionLock>()
  private state: LocalRecoveryState = { status: 'idle', message: null }
  private record: PlaybackRecoveryRecord | null = null
  private storageReady = false
  private storageFailed = false
  private generation = 0
  private released = false
  private fenceSafe = false
  private readonly recovered = new Set<string>()
  private write = Promise.resolve()

  constructor(server: string, account: string, storage: PlaybackRecoveryStorage = new IndexedDbPlaybackRecoveryStorage()) {
    this.server = server
    this.account = account
    this.storage = storage
  }

  wasRecovered(incarnationId: string): boolean { return this.recovered.has(incarnationId) }
  noteFence(safe: boolean): void { this.fenceSafe = safe }
  dispose(): void { this.generation++; this.releaseAfterFence(this.fenceSafe) }

  getState(): LocalRecoveryState { return this.state }
  isEligible(): boolean { return this.storageReady && !this.storageFailed && this.locks.has(this.tabId) && !this.released }

  private update(status: LocalRecoveryState['status'], message: string | null): void { this.state = { status, message } }

  /**
   * Takes the tab's lock and recovers the records of pages that have gone. `ownRecord` says what
   * to do with the record of this very tab: a new page recovers the one its predecessor left,
   * while a live page that still plays keeps its own, because a recovery would tell the server
   * that a playing tab had stopped.
   */
  async initialize(recover: (record: PlaybackRecoveryRecord) => Promise<void>, ownRecord: 'recover' | 'keep' = 'recover'): Promise<void> {
    const generation = ++this.generation
    const check = () => { if (generation !== this.generation) throw new Error('Playback recovery ownership changed.') }
    this.update('recovering', 'Checking previous local playback…')
    this.recovered.clear()
    this.released = false
    await this.write.catch(() => {})
    check()
    if (!navigator.locks) {
      this.update('unavailable', 'This browser cannot safely recover playback after a reload because Web Locks are unavailable.')
      return
    }
    try {
      if (!this.locks.has(this.tabId)) {
        this.tabId = readPlaybackTabId()
        let lock = await acquirePlaybackLock(this.server, this.account, this.tabId)
        if (generation !== this.generation) { lock?.release(); check() }
        if (!lock) {
          this.tabId = replacePlaybackTabId()
          lock = await acquirePlaybackLock(this.server, this.account, this.tabId)
          if (generation !== this.generation) { lock?.release(); check() }
        }
        if (!lock) throw new Error('This playback window could not acquire its execution lock.')
        this.locks.set(this.tabId, lock)
      }
      let records: PlaybackRecoveryRecord[]
      try { records = await this.storage.list(this.server, this.account); check(); this.storageReady = true }
      catch {
        check()
        this.storageReady = false
        this.update('unavailable', 'Local recovery storage is unavailable. Playback cannot be recovered automatically after this page closes.')
        return
      }
      let recovered = false
      let unavailable: string | null = null
      for (const record of records) {
        check()
        if (record.server !== this.server || record.account !== this.account || record.eligible !== true) continue
        if (ownRecord === 'keep' && record.tabId === this.tabId) continue
        if (!this.locks.has(record.tabId)) {
          const lock = await acquirePlaybackLock(this.server, this.account, record.tabId)
          if (generation !== this.generation) { lock?.release(); check() }
          if (!lock) continue
          this.locks.set(record.tabId, lock)
        }
        if (!Number.isFinite(Date.parse(record.recoveryExpiresAt)) || Date.parse(record.recoveryExpiresAt) <= Date.now()) {
          unavailable = 'A previous playback recovery credential expired. Stop that destination through its original connection if available.'
          continue
        }
        try {
          await recover(record)
          check()
        } catch (error) {
          check()
          const status = (error as { response?: { status?: number } }).response?.status
          if (status && [400, 403, 404, 409, 410, 422].includes(status)) {
            unavailable = 'The server refused a previous playback recovery proof. Its playback remains unconfirmed.'
            continue
          }
          throw error
        }
        this.recovered.add(record.incarnationId)
        if (this.record?.incarnationId === record.incarnationId) this.record = null
        recovered = true
        try { await this.storage.remove(record); check() }
        catch {
          check()
          this.storageFailed = true
          unavailable = 'Playback stopped, but its recovery record could not be removed. Automatic local recovery is unavailable until storage is repaired.'
          continue
        }
        if (record.tabId !== this.tabId) {
          this.locks.get(record.tabId)?.release()
          this.locks.delete(record.tabId)
        }
      }
      check()
      this.update(unavailable ? 'unavailable' : recovered ? 'recovered' : 'ready', unavailable ?? (recovered ? 'Previous local playback stopped. Resume when ready.' : null))
    } catch (error) {
      if (generation === this.generation) this.update('retryable', error instanceof Error ? error.message : 'Local playback recovery could not complete.')
      throw error
    }
  }

  markUnavailable(message: string): void { this.update('unavailable', message) }

  async registered(endpoint: RecoveryEndpoint, checkpoint: PlaybackRecoveryCheckpoint, qualified: boolean): Promise<void> {
    if (!qualified || !this.isEligible()) {
      if (this.state.status !== 'unavailable') this.markUnavailable('Automatic local recovery is unavailable for this browser’s current playback configuration.')
      return
    }
    if (!endpoint.recoveryCredential || !endpoint.recoveryExpiresAt || endpoint.recoveryTabId !== this.tabId) throw new Error('The server did not supply a valid local recovery credential.')
    const record: PlaybackRecoveryRecord = {
      server: this.server, account: this.account, tabId: this.tabId, incarnationId: endpoint.incarnationId,
      recoveryCredential: endpoint.recoveryCredential, recoveryExpiresAt: endpoint.recoveryExpiresAt, eligible: true, checkpoint,
    }
    try { await this.storage.put(record) }
    catch (error) { this.storageFailed = true; this.markUnavailable('Local playback recovery could not be saved. Retry to use ordinary playback without automatic recovery.'); throw error }
    this.record = record
  }

  async checkpoint(checkpoint: PlaybackRecoveryCheckpoint): Promise<void> {
    const record = this.record
    if (!record) return
    const updated = { ...record, checkpoint }
    this.write = this.write.catch(() => {}).then(async () => {
      await this.storage.put(updated)
      if (this.record?.incarnationId === updated.incarnationId) this.record = updated
    })
    try { await this.write }
    catch (error) {
      this.storageFailed = true
      this.markUnavailable('Playback recovery could not save its position. Local execution is paused until storage is available.')
      throw error
    }
  }

  releaseAfterFence(safe: boolean): void {
    if (!safe && this.record) return
    this.released = true
    this.locks.forEach(lock => lock.release())
    this.locks.clear()
  }
}
