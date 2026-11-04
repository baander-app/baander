import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, within } from '@testing-library/react'
import { KeyboardShortcutsHelp } from '../keyboard-shortcuts-help'
import { createShortcutDefinitions, type ShortcutDependencies } from '@/shared/lib/shortcut-definitions'
import { clearRegistry, registerShortcut, resetPlatformCache } from '@/shared/lib/shortcut-registry'

const KEY_SYMBOLS = /[←→↑↓⌘⇧↵⌫]/u

function renderHelp(platform: string) {
  vi.spyOn(navigator, 'platform', 'get').mockReturnValue(platform)
  resetPlatformCache()
  render(<KeyboardShortcutsHelp open onOpenChange={() => {}} />)

  return screen.getByRole('dialog')
}

function shortcutRow(description: string): HTMLElement {
  return screen.getByText(description).parentElement!
}

describe('keyboard shortcuts help', () => {
  beforeEach(() => {
    for (const entry of createShortcutDefinitions({} as ShortcutDependencies)) {
      registerShortcut(entry)
    }
  })

  afterEach(() => {
    cleanup()
    clearRegistry()
    resetPlatformCache()
    vi.restoreAllMocks()
  })

  it('shows macOS modifier and arrow keys as icons rather than symbol characters', () => {
    const dialog = renderHelp('MacIntel')

    expect(dialog.textContent).not.toMatch(KEY_SYMBOLS)
    expect(within(shortcutRow('Next track')).getByRole('img', { name: 'Command' })).toBeInTheDocument()
    expect(within(shortcutRow('Next track')).getByRole('img', { name: 'Right arrow' })).toBeInTheDocument()
    expect(within(shortcutRow('Seek backward 10 seconds')).getByRole('img', { name: 'Shift' })).toBeInTheDocument()
    expect(within(shortcutRow('Seek backward 10 seconds')).getByRole('img', { name: 'Left arrow' })).toBeInTheDocument()
    expect(within(shortcutRow('Volume up')).getByRole('img', { name: 'Up arrow' })).toBeInTheDocument()
    expect(within(shortcutRow('Volume down')).getByRole('img', { name: 'Down arrow' })).toBeInTheDocument()
    expect(within(shortcutRow('Append to queue')).getByRole('img', { name: 'Return' })).toBeInTheDocument()
    expect(within(shortcutRow('Go back')).getByRole('img', { name: 'Delete' })).toBeInTheDocument()
    expect(within(shortcutRow('Open spotlight')).getByText('K')).toBeInTheDocument()
  })

  it('names modifiers in words on other platforms and keeps arrow keys as icons', () => {
    const dialog = renderHelp('Linux x86_64')

    expect(dialog.textContent).not.toMatch(KEY_SYMBOLS)
    expect(within(shortcutRow('Next track')).getByText('Ctrl')).toBeInTheDocument()
    expect(within(shortcutRow('Next track')).getByRole('img', { name: 'Right arrow' })).toBeInTheDocument()
    expect(within(shortcutRow('Volume up (large)')).getByText('Shift')).toBeInTheDocument()
    expect(within(shortcutRow('Append to queue')).getByText('Enter')).toBeInTheDocument()
    expect(within(shortcutRow('Go back')).getByText('Backspace')).toBeInTheDocument()
  })
})
