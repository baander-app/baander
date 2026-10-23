import type Hls from 'hls.js'

interface AttachmentOptions {
  video: HTMLVideoElement
  url: string
  position: number
  onError: (failure?: 'network') => void
  onReady: () => void
}

export function attachHlsPlayback({ video, url, position, onError, onReady }: AttachmentOptions) {
  let disposed = false
  let player: Hls | null = null
  const active = () => !disposed
  const dispose = () => {
    if (disposed) return
    disposed = true
    const instance = player
    player = null
    instance?.destroy()
  }
  const fail = (failure?: 'network') => {
    if (!active()) return
    disposed = true
    const instance = player
    player = null
    try {
      onError(failure)
    } finally {
      // Destruction may reset the native clock. Let the owner retain its cursor
      // first, with callbacks already fenced against reentry.
      instance?.destroy()
    }
  }
  import('hls.js').then(({ default: HlsPlayer }) => {
    if (!active()) return
    if (!HlsPlayer.isSupported()) {
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url
        onReady()
      }
      else fail()
      return
    }
    const instance = new HlsPlayer({ autoStartLoad: false, startPosition: position })
    player = instance
    instance.on(HlsPlayer.Events.ERROR, (_event, data) => {
      if (active() && player === instance && data.fatal) fail(data.type === HlsPlayer.ErrorTypes.NETWORK_ERROR ? 'network' : undefined)
    })
    instance.on(HlsPlayer.Events.MEDIA_ATTACHED, () => {
      if (active() && player === instance) instance.loadSource(url)
    })
    instance.on(HlsPlayer.Events.MANIFEST_PARSED, () => {
      if (active() && player === instance) {
        instance.startLoad(position)
        onReady()
      }
    })
    instance.attachMedia(video)
  }).catch(() => {
    fail()
  })
  return dispose
}
