export type SubtitlePreference = 'off' | `s${number}`

type SelectionOptions = {
  video: HTMLVideoElement
  getPreference: () => SubtitlePreference | undefined
  onChange: (preference: SubtitlePreference) => void
}

const sourceKey = (track: TextTrack): SubtitlePreference | undefined => {
  const match = /\((s\d+)\)$/.exec(track.label)
  return match?.[1] as SubtitlePreference | undefined
}

export function observeSubtitleSelection({ video, getPreference, onChange }: SelectionOptions) {
  const list = video.textTracks
  let disposed = false
  let settled = false
  let previousTracks: TextTrack[] = []
  let previousSelection: SubtitlePreference | undefined
  const tracks = () => Array.from(list).filter(track => track.kind === 'subtitles' && sourceKey(track))
  const selection = (current: TextTrack[]): SubtitlePreference => {
    const showing = current.find(track => track.mode === 'showing')
    return showing ? sourceKey(showing)! : 'off'
  }
  const reconcile = () => {
    const current = tracks()
    const preference = getPreference()
    if (preference !== undefined) {
      for (const track of current) {
        const mode = sourceKey(track) === preference ? 'showing' : 'disabled'
        if (track.mode !== mode) track.mode = mode
      }
    }
    previousTracks = current
    previousSelection = selection(current)
    // An initially empty/disabled list is not a settled viewer choice of Off.
    if (settled && preference === undefined && current.length && previousSelection !== 'off') {
      onChange(previousSelection)
    }
  }
  const remember = (current: TextTrack[]) => {
    const next = selection(current)
    // Native change events are queued. Compare the resulting state rather than
    // treating a delayed restoration or teardown event as another viewer choice.
    if (!settled || !current.length || next === previousSelection) return
    previousSelection = next
    onChange(next)
  }
  const topologyChanged = () => {
    if (disposed) return
    const current = tracks()
    // Track additions or repeated metadata can overtake the native change event
    // for a gesture on tracks that remain attached. Retain that gesture first.
    if (previousTracks.every(track => current.includes(track))) remember(previousTracks)
    reconcile()
  }
  const changed = () => {
    if (disposed) return
    const current = tracks()
    if (!settled || current.length !== previousTracks.length
      || current.some((track, index) => track !== previousTracks[index])) {
      topologyChanged()
      return
    }
    remember(current)
  }
  const ready = () => {
    if (disposed) return
    if (settled) topologyChanged()
    else {
      settled = true
      reconcile()
    }
  }
  list.addEventListener('change', changed)
  list.addEventListener('addtrack', topologyChanged)
  list.addEventListener('removetrack', topologyChanged)
  video.addEventListener('loadedmetadata', ready)
  return {
    ready,
    dispose: () => {
      if (disposed) return
      changed()
      disposed = true
      list.removeEventListener('change', changed)
      list.removeEventListener('addtrack', topologyChanged)
      list.removeEventListener('removetrack', topologyChanged)
      video.removeEventListener('loadedmetadata', ready)
    },
  }
}
