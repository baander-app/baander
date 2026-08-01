import styled from 'styled-components'
import { useState, useCallback } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { SidebarEditor } from './SidebarEditor'
import { ContextPanel } from './ContextPanel'
import { SpotlightOverlay } from './SpotlightOverlay'
import { LyricsFullscreenOverlay } from './LyricsFullscreenOverlay'
import { ErrorBoundary } from '@/shared/components/ErrorBoundary'
import { useAudioPlayback } from '@/features/player/hooks/use-audio-playback'
import { useRadioAudio } from '@/features/radio/hooks/use-radio-audio'
import { useMediaSession } from '@/shared/hooks/use-media-session'
import { useSession } from '@/features/session/hooks/use-session'
import { SessionTransferPrompt } from '@/features/session/components/SessionTransferPrompt'
import { useContextPanelSelection } from '../hooks/use-context-panel-selection'
import { useKeyboardShortcuts } from '@/shared/hooks/use-keyboard-shortcuts'
import { useElectronPlaybackIpc } from '@/shared/hooks/use-electron-playback-ipc'
import { useMediaShortcuts } from '../hooks/use-media-shortcuts'
import { KeyboardShortcutsHelp } from '@/shared/components/keyboard-shortcuts-help'
import { Toaster } from '@/shared/components/ui/sonner'
import { MediatorDevPanel } from '@/shared/components/mediator-dev-panel'
import { TitleBar } from './TitleBar'
import { NowPlayingBar } from '@/features/player/components/NowPlayingBar'

const Shell = styled.div`
  display: flex;
  height: 100vh;
  flex-direction: column;
  background-color: var(--color-background);
`

const ShellRow = styled.div`
  display: flex;
  flex: 1;
  min-height: 0;
`

const MainContent = styled.main`
  flex: 1;
  overflow-y: auto;
`

export function AppShell() {
  const [helpOpen, setHelpOpen] = useState(false)

  useAudioPlayback()
  useRadioAudio()
  useMediaSession()
  const {
    showTransferPrompt,
    pendingSession,
    claim,
    claimWithQueue,
    newSession,
    dismissTransfer,
    isClaiming,
    isCreating,
  } = useSession()
  useContextPanelSelection()

  const toggleHelp = useCallback(() => setHelpOpen((o) => !o), [])

  useMediaShortcuts()

  useElectronPlaybackIpc()

  useKeyboardShortcuts({
    onToggleHelp: toggleHelp,
  })

  return (
    <Shell>
      {window.BaanderElectron && <TitleBar />}
      <ShellRow>
        <Sidebar />
        <MainContent>
          <ErrorBoundary>
            <Outlet />
          </ErrorBoundary>
        </MainContent>
        <ContextPanel />
      </ShellRow>
      <NowPlayingBar />
      <SidebarEditor />
      <LyricsFullscreenOverlay />
      <SpotlightOverlay />
      <KeyboardShortcutsHelp open={helpOpen} onOpenChange={setHelpOpen} />
      <Toaster />
      <MediatorDevPanel />
      <SessionTransferPrompt
        open={showTransferPrompt}
        session={pendingSession}
        onClaim={() => claim()}
        onClaimWithQueue={() => claimWithQueue()}
        onNew={() => newSession()}
        onDismiss={dismissTransfer}
        isClaiming={isClaiming}
        isCreating={isCreating}
      />
    </Shell>
  )
}
