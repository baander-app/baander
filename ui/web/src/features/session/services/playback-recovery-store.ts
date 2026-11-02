import type { PlaybackMediaResource } from '@/shared/api-client/gen/endpoints'

export interface PlaybackRecoveryCheckpoint {
  revision: number
  media: PlaybackMediaResource | null
  currentTrackIndex: number
  position: number
}

export interface PlaybackRecoveryRecord {
  server: string
  account: string
  tabId: string
  incarnationId: string
  recoveryCredential: string
  recoveryExpiresAt: string
  eligible: true
  checkpoint: PlaybackRecoveryCheckpoint
}

interface RecoveryBucket {
  id: string
  records: PlaybackRecoveryRecord[]
}

export interface PlaybackRecoveryStorage {
  list(server: string, account: string): Promise<PlaybackRecoveryRecord[]>
  put(record: PlaybackRecoveryRecord): Promise<void>
  remove(record: PlaybackRecoveryRecord): Promise<void>
}

export class IndexedDbPlaybackRecoveryStorage implements PlaybackRecoveryStorage {
  private open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open('baander-playback-recovery', 1)
      request.onupgradeneeded = () => {
        if (!request.result.objectStoreNames.contains('recovery')) request.result.createObjectStore('recovery', { keyPath: 'id' })
      }
      request.onerror = () => reject(request.error ?? new Error('Playback recovery storage could not open.'))
      request.onsuccess = () => resolve(request.result)
    })
  }

  private async access(server: string, account: string, change?: (records: PlaybackRecoveryRecord[]) => PlaybackRecoveryRecord[]): Promise<PlaybackRecoveryRecord[]> {
    const database = await this.open()
    return new Promise((resolve, reject) => {
      const transaction = database.transaction('recovery', change ? 'readwrite' : 'readonly')
      const store = transaction.objectStore('recovery')
      const id = JSON.stringify([server, account])
      const read = store.get(id)
      let records: PlaybackRecoveryRecord[] = []
      read.onsuccess = () => {
        const bucket = read.result as RecoveryBucket | undefined
        records = bucket?.records ?? []
        if (change) {
          records = change(records)
          store.put({ id, records } satisfies RecoveryBucket)
        }
      }
      transaction.oncomplete = () => { database.close(); resolve(records) }
      transaction.onerror = transaction.onabort = () => {
        database.close()
        reject(transaction.error ?? new Error('Playback recovery storage could not commit.'))
      }
    })
  }

  list(server: string, account: string): Promise<PlaybackRecoveryRecord[]> {
    return this.access(server, account)
  }

  async put(record: PlaybackRecoveryRecord): Promise<void> {
    await this.access(record.server, record.account, records => [
      ...records.filter(previous => previous.incarnationId !== record.incarnationId), record,
    ])
  }

  async remove(record: PlaybackRecoveryRecord): Promise<void> {
    await this.access(record.server, record.account, records => records.filter(previous => previous.incarnationId !== record.incarnationId))
  }
}
