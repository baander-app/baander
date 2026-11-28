import { fireEvent, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { VideoExtraResource } from '@/shared/api-client/gen/endpoints'
import { render } from '../../../../../tests/test-utils'
import { ExtrasRow } from '../ExtrasRow'

describe('extras shelf', () => {
  it('keeps a long shelf scrollable without mounting every file', async () => {
    const width = vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(416)
    const play = vi.fn()
    const extras: VideoExtraResource[] = Array.from({ length: 55 }, (_, index) => ({
      publicId: `video-${index}`,
      placementPublicId: `placement-${index}`,
      videoPublicId: `video-${index}`,
      kind: index === 54 ? 'interview' : 'trailer',
      width: null,
      height: null,
      duration: null,
      videoBitrate: null,
      positionSeconds: null,
      lastPlayedAt: null,
    }))

    try {
      render(<ExtrasRow extras={extras} onPlay={play} />)
      expect(screen.getAllByRole('button').length).toBeLessThan(extras.length)
      expect(screen.queryByRole('button', { name: 'Play Interview' })).not.toBeInTheDocument()

      const shelf = screen.getByLabelText('Extra files')
      shelf.scrollLeft = extras.length * 208 - 416
      fireEvent.scroll(shelf)
      fireEvent.click(await screen.findByRole('button', { name: 'Play Interview' }))

      expect(play).toHaveBeenCalledExactlyOnceWith({
        titleKind: 'extra',
        titleId: 'placement-54',
        videoId: 'video-54',
        startSeconds: 0,
        title: 'Interview',
        duration: null,
      })
    } finally {
      width.mockRestore()
    }
  })
})
