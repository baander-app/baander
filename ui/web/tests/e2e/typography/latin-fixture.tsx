import '@fontsource-variable/geist'
import '@fontsource-variable/geist-mono'
import '@fontsource-variable/noto-sans'
import '@fontsource-variable/noto-sans-thai'
import '@fontsource-variable/noto-sans-myanmar'
import '@fontsource-variable/noto-sans-sc'
import '@fontsource-variable/noto-sans-tc'
import '@fontsource-variable/noto-sans-jp'
import '@fontsource-variable/noto-sans-kr'
import '@fontsource-variable/noto-sans-mono'
import '@/index.css'
import '@/shared/i18n'
import { createRoot } from 'react-dom/client'
import { loadPrimaryFonts } from '@/shared/typography'
import { ThemeProvider } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { Badge } from '@/shared/components/ui/badge'
import { KeyboardShortcutsHelp } from '@/shared/components/keyboard-shortcuts-help'
import { VirtualSongRow } from '@/features/catalog/components/VirtualSongRow'
import { createShortcutDefinitions } from '@/shared/lib/shortcut-definitions'
import { registerShortcut } from '@/shared/lib/shortcut-registry'
import { LATIN_LABEL, TEST_STRINGS, TEXT_ROLES } from './strings'

function ignore() {}

for (const shortcut of createShortcutDefinitions({
  setIsPlaying: ignore, isPlaying: () => false, playNext: ignore, playPrevious: ignore, seekTo: ignore,
  getCurrentTime: () => 0, setVolume: ignore, getVolume: () => 1, toggleShuffle: ignore, toggleRepeat: ignore,
  toggleMute: ignore, clearQueue: ignore, getCurrentTrack: () => null, setContextPanelTab: ignore,
  toggleLyricsFullscreen: ignore, goBack: ignore, goToPlaying: ignore,
})) {
  registerShortcut(shortcut)
}

const LATIN_STRINGS = TEST_STRINGS.filter(({ key }) => key === 'en' || key === 'vi')

/** Interface and content text in Latin script only, the way a Latin-only library shows it. */
export function LatinPage() {
  return <main>
    <h1>Latin typography fixture</h1>
    <div style={{ position: 'relative', height: 32, width: 960 }}>
      <VirtualSongRow song={{ publicId: 'typographySong0000010', title: 'Dune: Part Two', artistName: 'Hans Zimmer', albumName: 'Dune: Part Two (Original Motion Picture Soundtrack)', albumLanguage: 'en', duration: 245, available: true }}
        index={0} isPlaying={false} isFocused={false} onPlay={ignore} onClick={ignore} height={32} translateY={0} />
    </div>
    <Badge variant="outline">FLAC</Badge>
    <ContentText textRole="label" text={LATIN_LABEL} />
    {TEXT_ROLES.flatMap((role) => LATIN_STRINGS.map(({ key, text }) => (
      <ContentText key={`${role}-${key}`} element="p" textRole={role} text={text} />
    )))}
    <KeyboardShortcutsHelp open onOpenChange={ignore} />
  </main>
}

await loadPrimaryFonts(document.fonts)
createRoot(document.getElementById('root')!).render(<ThemeProvider><LatinPage /></ThemeProvider>)
