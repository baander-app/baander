import styled from 'styled-components'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue
} from '@/shared/components/ui/select'
import { useVideoPresentation } from '../stores/video-presentation-store'
import type {
  VideoPlaybackInformation,
  VideoAudioTrack
} from '../hooks/use-video-playback-information'
import { CinemaTiming } from './CinemaControls'
import { textRole } from '@/shared/theme'

const Settings = styled.section`
  display: grid;
  gap: var(--space-md);
  label {
    display: grid;
    gap: var(--space-sm);
    ${textRole('label')}
  }
  p {
    margin: 0;
    ${textRole('caption')}
    color: var(--color-muted-foreground);
  }
`

const languageNames = new Intl.DisplayNames(['en'], { type: 'language' })
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
  jpn: 'ja',
  kor: 'ko',
  zho: 'zh',
  chi: 'zh',
  ara: 'ar',
  hin: 'hi',
  pol: 'pl',
  rus: 'ru',
  ukr: 'uk'
}

function audioTrackLabel(track: VideoAudioTrack) {
  const code = track.language?.toLowerCase()
  let language = code
  if (code) {
    try {
      language = languageNames.of(languageCodes[code] ?? code)
    } catch {
      language = code
    }
  }
  const name = [track.title, language].filter(Boolean).join(' · ')
  return name || `Track ${track.index + 1}`
}

export function CinemaSettings({
  information,
  local
}: {
  information?: VideoPlaybackInformation
  local: boolean
}) {
  const commands = useVideoPresentation((state) => state.commands)
  const available = useVideoPresentation((state) => state.controlsAvailable)
  const qualities = useVideoPresentation((state) => state.qualities)
  const quality = useVideoPresentation((state) => state.quality)
  const currentQuality = useVideoPresentation((state) => state.currentQuality)
  const subtitles = useVideoPresentation((state) => state.subtitles)
  const subtitle = useVideoPresentation((state) => state.subtitle)
  const subtitleOffset = useVideoPresentation((state) => state.subtitleOffsetMs)
  const audioIndex = useVideoPresentation((state) => state.audioStreamIndex)
  const audioOffset = useVideoPresentation((state) => state.audioOffsetMs)
  const pending = useVideoPresentation((state) => state.pendingAudio)
  const enabled = local && available && !!commands
  const changeAudio = (index: number | null, offset: number) => {
    if (!commands) return
    commands
      .setAudio(index, offset)
      .catch(useVideoPresentation.getState().reportError)
  }
  return (
    <Settings aria-label="Playback settings">
      {!enabled ? (
        <p>
          {local
            ? 'Track and quality controls are unavailable for this playback format.'
            : 'Track, quality and timing controls are available on the destination playing this movie.'}
        </p>
      ) : null}
      <label>
        Quality
        <Select
          value={quality ?? 'auto'}
          disabled={!enabled}
          onValueChange={(id) => commands?.setQuality(id)}
        >
          <SelectTrigger aria-label="Quality">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="auto">
              Auto{currentQuality ? ` · ${currentQuality}` : ''}
            </SelectItem>
            {qualities
              ?.filter((item) => item.id !== 'auto')
              .map((item) => (
                <SelectItem key={item.id} value={item.id}>
                  {item.label}
                </SelectItem>
              ))}
          </SelectContent>
        </Select>
      </label>
      {enabled && !qualities?.some((item) => item.id !== 'auto') ? (
        <p>
          Quality is selected automatically by this browser. Manual quality
          selection is unavailable.
        </p>
      ) : null}
      <label>
        Audio track
        <Select
          value={
            audioIndex === null || audioIndex === undefined
              ? 'default'
              : String(audioIndex)
          }
          disabled={!enabled || !!pending || !information?.audioTracks.length}
          onValueChange={(value) =>
            changeAudio(
              value === 'default' ? null : Number(value),
              audioOffset ?? 0
            )
          }
        >
          <SelectTrigger aria-label="Audio track">
            <SelectValue placeholder="Default audio" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="default">Default audio</SelectItem>
            {information?.audioTracks.map((track) => (
              <SelectItem key={track.index} value={String(track.index)}>
                {audioTrackLabel(track)} · {track.codec} · {track.channels}{' '}
                channels
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </label>
      {pending ? <p role="status">Changing audio…</p> : null}
      {information && !information.audioTracks.length ? (
        <p>This movie has no audio track.</p>
      ) : null}
      <CinemaTiming
        label="Audio timing"
        value={audioOffset ?? 0}
        disabled={!enabled || !!pending || !information?.audioTracks.length}
        onChange={(offset) => changeAudio(audioIndex ?? null, offset)}
      />
      <label>
        Subtitles
        <Select
          value={subtitle ?? 'off'}
          disabled={!enabled || !subtitles?.length}
          onValueChange={(id) => {
            if (id === 'off' || /^s\d+$/.test(id))
              commands?.setSubtitle(id as 'off' | `s${number}`)
          }}
        >
          <SelectTrigger aria-label="Subtitles">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="off">Off</SelectItem>
            {subtitles?.map((item) => (
              <SelectItem key={item.id} value={item.id}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </label>
      {!subtitles?.length ? (
        <p>
          No selectable subtitles are available. Only supported text tracks can
          be selected.
        </p>
      ) : null}
      <CinemaTiming
        label="Subtitle timing"
        value={subtitleOffset ?? 0}
        disabled={!enabled || subtitle === 'off' || !subtitles?.length}
        onChange={(offset) => commands?.setSubtitleOffset(offset)}
      />
      <p>
        Space or K: play or pause. Left and Right: skip 10 seconds. Up and Down:
        volume. M: mute. F: fullscreen. Escape: close the panel or player.
      </p>
      <p>
        Earlier moves the sound or text earlier. Later delays it. Adjust in 0.1
        second steps, up to 10 seconds each way.
      </p>
    </Settings>
  )
}
