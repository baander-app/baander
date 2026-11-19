import { cleanup, render, screen } from '@testing-library/react'
import { ThemeProvider } from 'styled-components'
import { afterEach, describe, expect, it } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { ScopeList } from '../ScopeList'

function mount(scopes: string[]) {
  return render(
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <ScopeList scopes={scopes} />
    </ThemeProvider>,
  )
}

describe('ScopeList', () => {
  afterEach(() => {
    cleanup()
  })

  it('describes each scope the server grants', () => {
    mount(['profile', 'email', 'library', 'playlist'])

    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'See your name and profile',
      'See your email address',
      'Browse and play your library, and control playback on your devices',
      'See, create and change your playlists',
    ])
  })

  it('shows nothing for scopes it does not know', () => {
    mount(['library', 'access-api', 'admin'])

    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Browse and play your library, and control playback on your devices',
    ])
  })
})
