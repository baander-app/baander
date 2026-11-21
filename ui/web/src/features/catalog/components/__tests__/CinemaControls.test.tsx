import { cleanup, fireEvent, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { CinemaTiming } from '../CinemaControls'

afterEach(cleanup)

describe('cinema timing', () => {
  it('uses signed milliseconds and changes each timing control independently', () => {
    const audio = vi.fn()
    const subtitle = vi.fn()
    render(
      <>
        <CinemaTiming
          label="Audio timing"
          value={100}
          disabled={false}
          onChange={audio}
        />
        <CinemaTiming
          label="Subtitle timing"
          value={-500}
          disabled={false}
          onChange={subtitle}
        />
      </>
    )
    expect(screen.getByLabelText('Audio timing offset')).toHaveTextContent(
      '+100 ms'
    )
    expect(screen.getByLabelText('Subtitle timing offset')).toHaveTextContent(
      '-500 ms'
    )
    fireEvent.click(screen.getByRole('button', { name: 'Audio timing later' }))
    expect(audio).toHaveBeenLastCalledWith(200)
    fireEvent.click(
      screen.getByRole('button', { name: 'Reset subtitle timing' })
    )
    expect(subtitle).toHaveBeenCalledExactlyOnceWith(0)
    expect(audio).toHaveBeenCalledOnce()
  })

  it('keeps the offset within ten seconds and disables unavailable corrections', () => {
    const change = vi.fn()
    const view = render(
      <CinemaTiming
        label="Audio timing"
        value={10000}
        disabled={false}
        onChange={change}
      />
    )
    expect(
      screen.getByRole('button', { name: 'Audio timing later' })
    ).toBeDisabled()
    view.rerender(
      <CinemaTiming label="Audio timing" value={0} disabled onChange={change} />
    )
    expect(
      screen.getByRole('button', { name: 'Audio timing earlier' })
    ).toBeDisabled()
    expect(
      screen.getByRole('button', { name: 'Audio timing later' })
    ).toBeDisabled()
  })
})
