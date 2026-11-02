export interface PlaybackExecutionLock {
  readonly tabId: string
  release(): void
}

export function playbackLockName(server: string, account: string, tabId: string): string {
  return `baander-playback:${JSON.stringify([server, account, tabId])}`
}

export function acquirePlaybackLock(server: string, account: string, tabId: string): Promise<PlaybackExecutionLock | null> {
  if (!navigator.locks) return Promise.reject(new Error('This browser does not support safe local playback recovery.'))
  return new Promise((resolve, reject) => {
    navigator.locks.request(playbackLockName(server, account, tabId), { mode: 'exclusive', ifAvailable: true }, lock => {
      if (!lock) { resolve(null); return }
      return new Promise<void>(release => {
        let released = false
        resolve({ tabId, release: () => { if (!released) { released = true; release() } } })
      })
    }).catch(reject)
  })
}

export function readPlaybackTabId(): string {
  const key = 'baander-playback-tab'
  const previous = sessionStorage.getItem(key)
  if (previous) return previous
  const id = crypto.randomUUID()
  sessionStorage.setItem(key, id)
  return id
}

export function replacePlaybackTabId(): string {
  const id = crypto.randomUUID()
  sessionStorage.setItem('baander-playback-tab', id)
  return id
}
