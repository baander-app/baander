import type Hls from 'hls.js'

interface AttachmentOptions {
  video: HTMLVideoElement
  url: string
  position: number
  onError: () => void
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
  const fail = () => {
    if (!active()) return
    dispose()
    onError()
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
      if (active() && player === instance && data.fatal) fail()
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
