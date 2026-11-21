import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react'
import { render } from '../../../../../tests/test-utils'
import { CinemaSettings } from '../CinemaSettings'
import { useVideoPresentation } from '../../stores/video-presentation-store'
import type { VideoPlaybackInformation } from '../../hooks/use-video-playback-information'

const information: VideoPlaybackInformation = {
  qualityChoices: [
    { id: '360p', label: '360p', width: 640, height: 360 },
    { id: '480p', label: '480p', width: 854, height: 480 },
    { id: '4K', label: '2160p / 4K', width: 3840, height: 2160 }
  ],
  audioTracks: [],
  subtitleTracks: [],
  chapters: [],
  preview: {
    state: 'unavailable',
    url: null,
    interval: 10,
    width: 160,
    height: 90,
    columns: 10,
    count: 0
  }
}

const audioInformation: VideoPlaybackInformation = {
  ...information,
  audioTracks: [
    {
      index: 3,
      language: 'dan',
      title: 'Danish track',
      codec: 'aac',
      channels: 2,
      isDefault: false
    }
  ]
}

const setQuality = vi.fn<(id: string) => Promise<void>>()
const setAudio = vi.fn<(index: number | null, offset: number) => Promise<void>>()

beforeEach(() => {
  setQuality.mockReset().mockResolvedValue(undefined)
  setAudio.mockReset().mockImplementation(async (audioStreamIndex, audioOffsetMs) => {
    useVideoPresentation.getState().publish({
      pendingAudio: { audioStreamIndex, audioOffsetMs }
    })
  })
  useVideoPresentation.getState().select('settings-video')
  useVideoPresentation.getState().publish({
    controlsAvailable: true,
    commands: {
      setQuality,
      setAudio,
      setSubtitle: () => {},
      setSubtitleOffset: () => {}
    }
  })
})

afterEach(() => {
  cleanup()
  useVideoPresentation.getState().select('')
})

function openQuality() {
  fireEvent.keyDown(screen.getByRole('combobox', { name: 'Quality' }), {
    key: 'ArrowDown'
  })
}

describe('CinemaSettings quality', () => {
  it('offers backend choices independently of current HLS levels and filters destination dimensions', () => {
    useVideoPresentation.getState().publish({
      qualityLimits: { maxWidth: 1920, maxHeight: 1080 },
      currentQuality: '720p'
    })
    render(<CinemaSettings information={information} local />)
    openQuality()
    expect(screen.getByRole('option', { name: 'Auto · 720p' })).toBeVisible()
    expect(screen.getByRole('option', { name: '360p' })).toBeVisible()
    expect(screen.getByRole('option', { name: '480p' })).toBeVisible()
    expect(screen.queryByRole('option', { name: '2160p / 4K' })).toBeNull()
  })

  it('shows the requested quality and keeps its accepted selection separate while pending', () => {
    useVideoPresentation.getState().publish({
      quality: '480p',
      pendingQuality: '4K'
    })
    render(<CinemaSettings information={information} local />)
    expect(
      screen.getByRole('combobox', { name: 'Quality' })
    ).toHaveTextContent('2160p / 4K')
    expect(screen.getByText('Accepted quality: 480p')).toBeVisible()
    expect(screen.getByText(/Changing quality to/)).toHaveTextContent(
      'Changing quality to 2160p / 4K'
    )
    openQuality()
    expect(screen.getByRole('option', { name: '2160p / 4K' })).toBeVisible()
  })

  it('explains unavailable manual choices for sources with unknown or small dimensions', () => {
    render(
      <CinemaSettings information={{ ...information, qualityChoices: [] }} local />
    )
    expect(
      screen.getByText(/Sources with unknown dimensions or below 360p use Auto/)
    ).toBeVisible()
    openQuality()
    expect(screen.getAllByRole('option')).toHaveLength(1)
  })

  it.each(['track', 'offset'] as const)(
    'merges a pending audio %s with the next companion setting',
    async (firstSetting) => {
      render(<CinemaSettings information={audioInformation} local />)
      const changeTrack = async () => {
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Audio track' }), {
          key: 'ArrowDown'
        })
        await act(async () => {
          fireEvent.click(screen.getByRole('option', { name: /Danish track/ }))
        })
      }
      const changeOffset = async () => {
        await act(async () => {
          fireEvent.click(
            screen.getByRole('button', { name: 'Audio timing later' })
          )
        })
      }
      if (firstSetting === 'track') {
        await changeTrack()
        await changeOffset()
      } else {
        await changeOffset()
        await changeTrack()
      }
      expect(setAudio).toHaveBeenLastCalledWith(3, 100)
      expect(useVideoPresentation.getState().pendingAudio).toEqual({
        audioStreamIndex: 3,
        audioOffsetMs: 100
      })
      expect(useVideoPresentation.getState().audioStreamIndex).toBeNull()
      expect(useVideoPresentation.getState().audioOffsetMs).toBe(0)
    }
  )

  it('allows returning to accepted Auto while a manual quality request is pending', async () => {
    useVideoPresentation.getState().publish({ pendingQuality: '480p' })
    render(<CinemaSettings information={information} local />)
    openQuality()
    await act(async () => {
      fireEvent.click(screen.getByRole('option', { name: 'Auto' }))
    })
    expect(setQuality).toHaveBeenCalledWith('auto')
  })

  it('allows returning to accepted default audio while a track request is pending', async () => {
    useVideoPresentation.getState().publish({
      pendingAudio: { audioStreamIndex: 3, audioOffsetMs: 600 }
    })
    render(<CinemaSettings information={audioInformation} local />)
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Audio track' }), {
      key: 'ArrowDown'
    })
    await act(async () => {
      fireEvent.click(screen.getByRole('option', { name: 'Default audio' }))
    })
    expect(setAudio).toHaveBeenCalledWith(null, 600)
  })

  it('accumulates repeated timing gestures against the pending offset', async () => {
    render(<CinemaSettings information={audioInformation} local />)
    const later = screen.getByRole('button', { name: 'Audio timing later' })
    await act(async () => fireEvent.click(later))
    await act(async () => fireEvent.click(later))
    expect(setAudio).toHaveBeenLastCalledWith(null, 200)
    expect(screen.getByLabelText('Audio timing offset')).toHaveTextContent(
      '+200 ms'
    )
    expect(useVideoPresentation.getState().audioOffsetMs).toBe(0)
  })

  it.each(['failure', 'superseded'] as const)(
    'handles an asynchronous quality %s without claiming acceptance',
    async (outcome) => {
      const error = outcome === 'superseded'
        ? new DOMException('Replaced.', 'AbortError')
        : new Error('Quality admission failed.')
      setQuality.mockRejectedValueOnce(error)
      render(<CinemaSettings information={information} local />)
      openQuality()
      await act(async () => {
        fireEvent.click(screen.getByRole('option', { name: '480p' }))
      })
      await waitFor(() => expect(setQuality).toHaveBeenCalledWith('480p'))
      expect(useVideoPresentation.getState().quality).toBe('auto')
      expect(useVideoPresentation.getState().error).toBe(
        outcome === 'superseded' ? null : error.message
      )
    }
  )
})
