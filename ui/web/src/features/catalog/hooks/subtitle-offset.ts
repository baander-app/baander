import { assertTimingOffset } from './video-presentation'

/** Cue times always derive from their original baseline, including cues arriving later. */
export function observeSubtitleOffset(
  video: HTMLVideoElement,
  initialOffsetMs = 0
) {
  assertTimingOffset(initialOffsetMs)
  let offsetMs = initialOffsetMs
  let disposed = false
  const baselines = new Map<TextTrackCue, {
    start: number
    end: number
  }>()
  const tracks = new Set<TextTrack>()
  const refresh = () => {
    if (disposed) return
    for (const track of Array.from(video.textTracks)) {
      if (track.kind !== 'subtitles' && track.kind !== 'captions') continue
      if (!tracks.has(track)) {
        tracks.add(track)
        track.addEventListener('cuechange', refresh)
      }
      for (const cue of Array.from(track.cues ?? [])) {
        let baseline = baselines.get(cue)
        if (!baseline) {
          baseline = { start: cue.startTime, end: cue.endTime }
          baselines.set(cue, baseline)
        }
        const start = baseline.start + offsetMs / 1000
        const end = baseline.end + offsetMs / 1000
        if (cue.startTime !== start) cue.startTime = start
        if (cue.endTime !== end) cue.endTime = end
      }
    }
  }
  const timer = window.setInterval(refresh, 250)
  video.textTracks.addEventListener?.('addtrack', refresh)
  video.addEventListener('loadedmetadata', refresh)
  refresh()
  return {
    refresh,
    setOffset(ms: number) {
      assertTimingOffset(ms)
      if (disposed) return
      offsetMs = ms
      refresh()
    },
    dispose() {
      if (disposed) return
      disposed = true
      window.clearInterval(timer)
      video.textTracks.removeEventListener?.('addtrack', refresh)
      video.removeEventListener('loadedmetadata', refresh)
      for (const track of tracks)
        track.removeEventListener('cuechange', refresh)
      for (const [cue, baseline] of baselines) {
        cue.startTime = baseline.start
        cue.endTime = baseline.end
      }
      baselines.clear()
    }
  }
}
