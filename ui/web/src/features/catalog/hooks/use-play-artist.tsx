import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { isCancel } from 'axios'
import { useCallback } from 'react'
import { toast } from 'sonner'
import styled from 'styled-components'
import { getSongIndex } from '@/shared/api-client/gen/endpoints'
import { Button } from '@/shared/components/ui/button'
import { createLogger } from '@/shared/lib/logger'
import { textRole } from '@/shared/theme'
import { readAllCursorPages, type CursorRead } from '@/shared/utils/read-all-cursor-pages'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'

const logger = createLogger('use-play-artist')

/** The longest queue a playback session accepts (`ControlPlaybackRequest::MAX_QUEUE`). */
const SESSION_QUEUE_LIMIT = 10_000

/** The song list's largest page. */
const SONG_PAGE_SIZE = 100

const FailureMessage = styled.span`
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

export interface PlayArtistOptions {
  shuffle?: boolean
}

type PlayTrack = (track: Track, queue?: Track[]) => void

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function availableTrack(song: unknown, artistName: string): Track[] {
  if (!record(song) || song.available !== true) {
    return []
  }

  return [{
    publicId: String(song.publicId ?? ''),
    available: true,
    title: String(song.title ?? ''),
    artistName: artistName || undefined,
    albumName: song.albumName ? String(song.albumName) : undefined,
    albumLanguage: typeof song.albumLanguage === 'string' ? song.albumLanguage : undefined,
    albumPublicId: typeof song.albumId === 'string' ? song.albumId : undefined,
    duration: typeof song.length === 'number' ? song.length : undefined,
  }]
}

function shuffled(tracks: Track[]): Track[] {
  return [...tracks].sort(() => Math.random() - 0.5)
}

function showReadFailure(retry: () => void): void {
  const id = toast.error(
    <FailureMessage>{"The artist's songs could not be loaded."}</FailureMessage>,
    {
      action: (
        <Button
          variant="ghost"
          size="xs"
          onClick={() => {
            toast.dismiss(id)
            retry()
          }}
        >
          Retry
        </Button>
      ),
    },
  )
}

/**
 * Reads every song of the artist, up to the session's queue limit, and plays the
 * available ones. A failed page plays nothing and offers a retry.
 */
async function playArtistSongs(
  playTrack: PlayTrack,
  artistPublicId: string,
  artistName: string,
  options: PlayArtistOptions,
): Promise<void> {
  const owner = captureOwnership()
  let read: CursorRead<unknown>
  try {
    read = await readAllCursorPages(
      (cursor) => getSongIndex({
        artistId: artistPublicId,
        limit: SONG_PAGE_SIZE,
        ...(cursor ? { cursor } : {}),
      }),
      {
        maxRows: SESSION_QUEUE_LIMIT,
        owner,
      },
    )
  } catch (error) {
    if (isCancel(error) || !isCurrentOwnership(owner)) {
      return
    }
    logger.warn('Failed to fetch artist songs:', error)
    showReadFailure(() => {
      playArtistSongs(playTrack, artistPublicId, artistName, options).catch((retryError: unknown) => {
        logger.error('Failed to retry the artist songs:', retryError)
      })
    })
    return
  }

  const tracks = read.rows.flatMap((song) => availableTrack(song, artistName))
  if (tracks.length === 0) {
    logger.warn('No playable songs found for artist', artistPublicId)
    return
  }

  const queue = options.shuffle ? shuffled(tracks) : tracks
  playTrack(queue[0], queue)

  if (read.truncated) {
    const limit = SESSION_QUEUE_LIMIT.toLocaleString('en-US')
    toast.info(`A queue holds at most ${limit} songs, so the artist's later songs were left out.`)
  }
}

/** Returns a `playArtist` callback that queues the artist's songs and starts playback. */
export function usePlayArtist() {
  const playTrack = usePlayerStore((s) => s.playTrack)

  const playArtist = useCallback(
    (artistPublicId: string, artistName: string, options: PlayArtistOptions = {}) =>
      playArtistSongs(playTrack, artistPublicId, artistName, options),
    [playTrack],
  )

  return { playArtist }
}
