import { ipcRenderer } from 'electron'

export const playbackBridge = {
  onToggle: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('baander:playback:toggle', handler)
    return () => ipcRenderer.removeListener('baander:playback:toggle', handler as any)
  },
  onNext: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('baander:playback:next', handler)
    return () => ipcRenderer.removeListener('baander:playback:next', handler as any)
  },
  onPrevious: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('baander:playback:previous', handler)
    return () => ipcRenderer.removeListener('baander:playback:previous', handler as any)
  },
  onSeekForward: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('baander:playback:seek-forward', handler)
    return () => ipcRenderer.removeListener('baander:playback:seek-forward', handler as any)
  },
  onSeekBackward: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('baander:playback:seek-backward', handler)
    return () => ipcRenderer.removeListener('baander:playback:seek-backward', handler as any)
  },
} as const

export type PlaybackBridge = typeof playbackBridge
