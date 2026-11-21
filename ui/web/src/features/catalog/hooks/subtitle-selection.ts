export type SubtitlePreference = 'off' | `s${number}`

type SelectionOptions = {
  video: HTMLVideoElement
  getPreference: () => SubtitlePreference | undefined
  onChange: (preference: SubtitlePreference) => void
  onChoices?: (
    choices: {
      id: string
      label: string
    }[]
  ) => void
}

export const sourceKey = (track: TextTrack): SubtitlePreference | undefined => {
  const match = /\((s\d+)\)$/.exec(track.label)
  return match?.[1] as SubtitlePreference | undefined
}

const languageCodes: Record<string, string> = {
  eng: 'en',
  dan: 'da',
  deu: 'de',
  ger: 'de',
  fra: 'fr',
  fre: 'fr',
  spa: 'es',
  ita: 'it',
  por: 'pt',
  nld: 'nl',
  dut: 'nl',
  swe: 'sv',
  nor: 'no',
  fin: 'fi',
  isl: 'is',
  ice: 'is',
  pol: 'pl',
  ces: 'cs',
  cze: 'cs',
  jpn: 'ja',
  zho: 'zh',
  chi: 'zh',
  kor: 'ko',
  ara: 'ar',
  hin: 'hi',
  rus: 'ru',
  ukr: 'uk',
  tur: 'tr',
  heb: 'he',
  ell: 'el',
  gre: 'el',
  hun: 'hu',
  ron: 'ro',
  rum: 'ro',
  tha: 'th',
  vie: 'vi'
}

function subtitleLabel(track: TextTrack): string {
  const code = languageCodes[track.language] ?? track.language
  let language = code
  try {
    if (code) {
      language =
        new Intl.DisplayNames([navigator.language], { type: 'language' }).of(
          code
        ) ?? code
    }
  } catch {
    // Unrecognized source language identifiers remain visible as supplied.
  }
  const title = track.label.replace(/\s*\(s\d+\)$/, '').trim()
  if (
    title &&
    language &&
    title.toLocaleLowerCase() !== language.toLocaleLowerCase()
  ) {
    return `${title} (${language})`
  }
  return title || language || track.label
}

export function observeSubtitleSelection({
  video,
  getPreference,
  onChange,
  onChoices
}: SelectionOptions) {
  const list = video.textTracks
  let disposed = false
  let settled = false
  let previousTracks: TextTrack[] = []
  let previousSelection: SubtitlePreference | undefined
  const tracks = () =>
    Array.from(list).filter(
      (track) => track.kind === 'subtitles' && sourceKey(track)
    )
  const selection = (current: TextTrack[]): SubtitlePreference => {
    const showing = current.find((track) => track.mode === 'showing')
    return showing ? sourceKey(showing)! : 'off'
  }
  const reconcile = () => {
    const current = tracks()
    onChoices?.(
      current.map((track) => ({
        id: sourceKey(track)!,
        label: subtitleLabel(track)
      }))
    )
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
    if (
      settled &&
      preference === undefined &&
      current.length &&
      previousSelection !== 'off'
    ) {
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
    if (previousTracks.every((track) => current.includes(track)))
      remember(previousTracks)
    reconcile()
  }
  const changed = () => {
    if (disposed) return
    const current = tracks()
    if (
      !settled ||
      current.length !== previousTracks.length ||
      current.some((track, index) => track !== previousTracks[index])
    ) {
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
    refresh: () => {
      if (!disposed) reconcile()
    },
    dispose: () => {
      if (disposed) return
      changed()
      disposed = true
      list.removeEventListener('change', changed)
      list.removeEventListener('addtrack', topologyChanged)
      list.removeEventListener('removetrack', topologyChanged)
      video.removeEventListener('loadedmetadata', ready)
    }
  }
}
