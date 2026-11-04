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
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import { Badge } from '@/shared/components/ui/badge'
import { VirtualSongRow } from '@/features/catalog/components/VirtualSongRow'
import { BrowserColumn, type BrowserItem } from '@/features/catalog/components/BrowserColumn'
import type { SongEntry } from '@/features/catalog/types'
import {
  BURMESE_TITLE, LATIN_LABEL, MIXED_BURMESE_TITLE, TEST_STRINGS, TEXT_ROLES, THAI_LABEL,
} from './strings'

const ROW_HEIGHT = 32

/** Song rows as the song list shows them, with stored album languages. */
const SONGS: SongEntry[] = [
  { publicId: 'typographySong0000001', title: '直', artistName: '坂本龍一', albumName: '音楽図鑑', albumLanguage: 'ja', duration: 245, available: true },
  { publicId: 'typographySong0000002', title: '直', artistName: '周杰倫', albumName: '音楽図鑑', albumLanguage: 'ja', duration: 9, available: true },
  { publicId: 'typographySong0000003', title: BURMESE_TITLE, artistName: 'မြန်မာစာ', albumName: 'နေကောင်းပါသလား', albumLanguage: 'my', duration: 600, available: true },
  { publicId: 'typographySong0000004', title: 'ภาษาไทยทดสอบการแสดงผล', artistName: 'รับชมต่อ', albumName: THAI_LABEL, albumLanguage: 'th', duration: 3599, available: true },
  { publicId: 'typographySong0000005', title: 'Tiếng Việt có dấu: đường, ề, ộ, ư', artistName: 'Продолжить просмотр', albumName: 'The quick brown fox', duration: 61, available: true },
  { publicId: 'typographySong0000006', title: '続きを見る', artistName: '계속 보기', albumName: '繼續觀看', albumLanguage: 'en', duration: 125, available: true },
]

/** Column-browser rows with stored languages and secondary names. */
const BROWSER_ITEMS: BrowserItem[] = [
  { id: 'burmese', label: BURMESE_TITLE, language: 'my', sublabels: ['မြန်မာစာ'] },
  { id: 'thai', label: 'ภาษาไทยทดสอบการแสดงผล', language: 'th', sublabels: ['รับชมต่อ'] },
  { id: 'japanese', label: '直', language: 'ja', sublabels: ['坂本龍一'] },
  { id: 'vietnamese', label: 'Tiếng Việt: đường, ề, ộ, ư', sublabels: ['Сезон 2'] },
  { id: 'mixed', label: MIXED_BURMESE_TITLE, sublabels: ['시즌 2'] },
]

function ignore() {}

export function RoleMatrix() {
  return <section aria-label="Type roles">
    {TEXT_ROLES.map((role) => <div key={role} data-testid={`role-${role}`}>
      <h2>{role}</h2>
      {TEST_STRINGS.map(({ key, text, language }) => (
        <ContentText key={key} as="p" textRole={role} text={text} language={language} data-testid={`${role}-${key}`} />
      ))}
    </div>)}
  </section>
}

export function SongRows() {
  return <section aria-label="Song rows">
    <div data-testid="song-list" style={{ position: 'relative', height: SONGS.length * ROW_HEIGHT, width: 960 }}>
      {SONGS.map((song, index) => (
        <VirtualSongRow key={song.publicId} song={song} index={index} isPlaying={false} isFocused={false}
          onPlay={ignore} onClick={ignore} height={ROW_HEIGHT} translateY={index * ROW_HEIGHT} />
      ))}
    </div>
  </section>
}

export function ColumnBrowser() {
  return <section aria-label="Column browser" data-testid="column-browser" style={{ display: 'flex', height: 240, width: 360 }}>
    <div style={{ flex: 1, minWidth: 0 }}>
      <BrowserColumn title="Albums" items={BROWSER_ITEMS} selectedId={null} onSelect={ignore} />
    </div>
  </section>
}

export function BadgesAndLabels() {
  return <section aria-label="Badges and labels" data-testid="badges-and-labels">
    <Badge data-testid="badge-thai"><ContentText textRole="caption" text={THAI_LABEL} /></Badge>
    <Badge data-testid="badge-burmese" variant="secondary"><ContentText textRole="caption" text={BURMESE_TITLE} /></Badge>
    <Badge data-testid="badge-codec" variant="outline">FLAC</Badge>
    <ContentText data-testid="label-thai" textRole="label" text={THAI_LABEL} />
    <ContentText data-testid="label-latin" textRole="label" text={LATIN_LABEL} />
  </section>
}

export function StoredLanguages() {
  return <section aria-label="Stored languages">
    <ContentText as="p" data-testid="burmese-body" textRole="body" text={BURMESE_TITLE} />
    <ContentText as="p" data-testid="mixed-burmese-body" textRole="body" text={MIXED_BURMESE_TITLE} />
    <ContentText as="p" data-testid="latin-label-stored-ja" textRole="label" text={LATIN_LABEL} language="ja" />
    <ContentText as="p" data-testid="mixed-burmese-stored-my" textRole="body" text={MIXED_BURMESE_TITLE} language="my" />
    <ContentText as="p" data-testid="thai-body-stored-ja" textRole="body" text="ภาษาไทยทดสอบการแสดงผล" language="ja" />
  </section>
}

await loadPrimaryFonts(document.fonts)
createRoot(document.getElementById('root')!).render(<ThemeProvider>
  <QueryClientProvider client={new QueryClient()}>
    <main>
      <h1>Typography fixture</h1>
      <SongRows />
      <ColumnBrowser />
      <BadgesAndLabels />
      <StoredLanguages />
      <RoleMatrix />
    </main>
  </QueryClientProvider>
</ThemeProvider>)
