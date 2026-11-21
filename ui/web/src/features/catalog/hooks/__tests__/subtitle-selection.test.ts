import { describe, expect, it, vi } from 'vitest'
import {
  observeSubtitleSelection,
  type SubtitlePreference
} from '../subtitle-selection'

function fixture() {
  const video = document.createElement('video')
  const tracks = [
    {
      kind: 'subtitles',
      label: 'Commentary (s3)',
      language: 'eng',
      mode: 'disabled'
    },
    {
      kind: 'subtitles',
      label: 'Dansk (s7)',
      language: 'dan',
      mode: 'disabled'
    }
  ]
  const list = Object.assign(new EventTarget(), {
    [Symbol.iterator]: () => tracks[Symbol.iterator]()
  })
  Object.defineProperty(video, 'textTracks', { value: list })
  return { video, tracks, list }
}

describe('subtitle source selection', () => {
  it('uses source keys and explicit Off while keeping language and title visible', () => {
    const { video, tracks } = fixture()
    let preference: SubtitlePreference = 's7'
    const choices = vi.fn()
    const observer = observeSubtitleSelection({
      video,
      getPreference: () => preference,
      onChange: vi.fn(),
      onChoices: choices
    })
    observer.ready()
    expect(tracks.map((track) => track.mode)).toEqual(['disabled', 'showing'])
    expect(choices).toHaveBeenCalledWith([
      { id: 's3', label: 'Commentary (English)' },
      { id: 's7', label: 'Dansk (Danish)' }
    ])
    preference = 'off'
    observer.refresh()
    expect(tracks.map((track) => track.mode)).toEqual(['disabled', 'disabled'])
    observer.dispose()
  })

  it('restores the source key when replacement tracks arrive and fences disposed refreshes', () => {
    const { video, tracks, list } = fixture()
    const observer = observeSubtitleSelection({
      video,
      getPreference: () => 's7',
      onChange: vi.fn()
    })
    observer.ready()
    tracks.splice(1, 1)
    list.dispatchEvent(new Event('removetrack'))
    const replacement = {
      kind: 'subtitles',
      label: 'Dansk (s7)',
      language: 'dan',
      mode: 'disabled'
    }
    tracks.unshift(replacement)
    list.dispatchEvent(new Event('addtrack'))
    expect(replacement.mode).toBe('showing')
    observer.dispose()
    replacement.mode = 'disabled'
    observer.refresh()
    list.dispatchEvent(new Event('change'))
    expect(replacement.mode).toBe('disabled')
  })
})
