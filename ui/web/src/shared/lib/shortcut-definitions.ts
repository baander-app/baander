import {
  ShortcutCategory,
  type ShortcutEntry,
} from './shortcut-registry'

export interface ShortcutDependencies {
  // Player store
  setIsPlaying: (playing: boolean) => void
  isPlaying: () => boolean
  playNext: () => void
  playPrevious: () => void
  seekTo: (time: number) => void
  getCurrentTime: () => number
  setVolume: (volume: number) => void
  getVolume: () => number
  toggleShuffle: () => void
  toggleRepeat: () => void
  toggleMute: () => void
  clearQueue: () => void
  getCurrentTrack: () => {
    publicId: string
    albumPublicId?: string
    title: string
  } | null

  // Context panel store
  setContextPanelTab: (tab: 'queue' | 'lyrics' | 'details' | 'info') => void

  // Lyrics fullscreen
  toggleLyricsFullscreen: () => void

  // Navigation
  goBack: () => void
  goToPlaying: () => void

  // Callbacks
  onToggleHelp?: () => void
  onFocusSearch?: () => void
}

export function createShortcutDefinitions(deps: ShortcutDependencies): ShortcutEntry[] {
  return [
    // ── Transport ──
    {
      id: 'transport.play-pause',
      category: ShortcutCategory.Transport,
      keys: { mac: 'Space', default: 'Space' },
      description: 'Play / Pause',
      matches: (e) => e.key === ' ' && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey,
      action: () => deps.setIsPlaying(!deps.isPlaying()),
    },
    {
      id: 'transport.next',
      category: ShortcutCategory.Transport,
      keys: { mac: '⌘→', default: 'Ctrl+→' },
      description: 'Next track',
      matches: (e) => e.key === 'ArrowRight' && (e.metaKey || e.ctrlKey) && !e.shiftKey,
      action: () => deps.playNext(),
    },
    {
      id: 'transport.previous',
      category: ShortcutCategory.Transport,
      keys: { mac: '⌘←', default: 'Ctrl+←' },
      description: 'Previous track',
      matches: (e) => e.key === 'ArrowLeft' && (e.metaKey || e.ctrlKey) && !e.shiftKey,
      action: () => deps.playPrevious(),
    },
    {
      id: 'transport.seek-forward',
      category: ShortcutCategory.Transport,
      keys: { mac: '⇧→', default: 'Shift+→' },
      description: 'Seek forward 10 seconds',
      matches: (e) => e.key === 'ArrowRight' && e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.seekTo(deps.getCurrentTime() + 10),
    },
    {
      id: 'transport.seek-backward',
      category: ShortcutCategory.Transport,
      keys: { mac: '⇧←', default: 'Shift+←' },
      description: 'Seek backward 10 seconds',
      matches: (e) => e.key === 'ArrowLeft' && e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.seekTo(Math.max(0, deps.getCurrentTime() - 10)),
    },
    {
      id: 'transport.shuffle',
      category: ShortcutCategory.Transport,
      keys: { mac: 'S', default: 'S' },
      description: 'Toggle shuffle',
      matches: (e) => e.key === 's' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.toggleShuffle(),
    },
    {
      id: 'transport.repeat',
      category: ShortcutCategory.Transport,
      keys: { mac: 'R', default: 'R' },
      description: 'Cycle repeat mode',
      matches: (e) => e.key === 'r' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.toggleRepeat(),
    },
    {
      id: 'transport.mute',
      category: ShortcutCategory.Transport,
      keys: { mac: 'M', default: 'M' },
      description: 'Toggle mute',
      matches: (e) => e.key === 'm' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.toggleMute(),
    },

    // ── Volume ──
    {
      id: 'volume.up',
      category: ShortcutCategory.Volume,
      keys: { mac: '↑', default: '↑' },
      description: 'Volume up',
      matches: (e) => e.key === 'ArrowUp' && !e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.setVolume(Math.min(100, deps.getVolume() + 5)),
    },
    {
      id: 'volume.down',
      category: ShortcutCategory.Volume,
      keys: { mac: '↓', default: '↓' },
      description: 'Volume down',
      matches: (e) => e.key === 'ArrowDown' && !e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.setVolume(Math.max(0, deps.getVolume() - 5)),
    },
    {
      id: 'volume.up-large',
      category: ShortcutCategory.Volume,
      keys: { mac: '⇧↑', default: 'Shift+↑' },
      description: 'Volume up (large)',
      matches: (e) => e.key === 'ArrowUp' && e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.setVolume(Math.min(100, deps.getVolume() + 10)),
    },
    {
      id: 'volume.down-large',
      category: ShortcutCategory.Volume,
      keys: { mac: '⇧↓', default: 'Shift+↓' },
      description: 'Volume down (large)',
      matches: (e) => e.key === 'ArrowDown' && e.shiftKey && !e.metaKey && !e.ctrlKey,
      action: () => deps.setVolume(Math.max(0, deps.getVolume() - 10)),
    },

    // ── Navigation ──
    {
      id: 'navigation.go-back',
      category: ShortcutCategory.Navigation,
      keys: { mac: '⌫', default: 'Backspace' },
      description: 'Go back',
      matches: (e) => e.key === 'Backspace' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.goBack(),
    },
    {
      id: 'navigation.go-to-playing',
      category: ShortcutCategory.Navigation,
      keys: { mac: 'G', default: 'G' },
      description: 'Go to playing track',
      matches: (e) => e.key === 'g' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.goToPlaying(),
      enabled: () => deps.getCurrentTrack() !== null,
    },

    // ── Search ──
    {
      id: 'search.focus',
      category: ShortcutCategory.Search,
      keys: { mac: '/', default: '/' },
      description: 'Focus search',
      matches: (e) => e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.onFocusSearch?.(),
    },
    {
      id: 'search.spotlight',
      category: ShortcutCategory.Search,
      keys: { mac: '⌘K', default: 'Ctrl+K' },
      description: 'Open spotlight',
      // Display-only: SpotlightOverlay owns the keydown listener
      matches: () => false,
      action: undefined,
    },
    {
      id: 'search.help',
      category: ShortcutCategory.Search,
      keys: { mac: '?', default: '?' },
      description: 'Keyboard shortcuts',
      hidden: true,
      matches: (e) => e.key === '?' && e.shiftKey,
      action: () => deps.onToggleHelp?.(),
    },

    // ── Media Type (Cmd+1-6) — display-only ──
    ...createMediaTypeEntries(),

    // ── View Mode (1-6) — display-only ──
    ...createViewModeEntries(),

    // ── Queue & Panel ──
    {
      id: 'panel.queue',
      category: ShortcutCategory.Queue,
      keys: { mac: 'Q', default: 'Q' },
      description: 'Queue panel',
      matches: (e) => e.key === 'q' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.setContextPanelTab('queue'),
    },
    {
      id: 'panel.lyrics',
      category: ShortcutCategory.Queue,
      keys: { mac: 'L', default: 'L' },
      description: 'Lyrics panel',
      matches: (e) => e.key === 'l' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.setContextPanelTab('lyrics'),
    },
    {
      id: 'panel.info',
      category: ShortcutCategory.Queue,
      keys: { mac: 'I', default: 'I' },
      description: 'Info panel',
      matches: (e) => e.key === 'i' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.setContextPanelTab('info'),
    },
    {
      id: 'panel.clear-queue',
      category: ShortcutCategory.Queue,
      keys: { mac: 'C', default: 'C' },
      description: 'Clear queue',
      matches: (e) => e.key === 'c' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => {
        deps.clearQueue()
        // Toast is shown by the caller (hook)
      },
    },

    // ── General ──
    {
      id: 'general.escape',
      category: ShortcutCategory.General,
      keys: { mac: 'Esc', default: 'Esc' },
      description: 'Close overlay / panel / dialog',
      // Display-only: Escape is handled natively by Dialog components
      matches: () => false,
    },
    {
      id: 'general.fullscreen-lyrics',
      category: ShortcutCategory.General,
      keys: { mac: 'F', default: 'F' },
      description: 'Fullscreen lyrics',
      matches: (e) => e.key === 'f' && !e.metaKey && !e.ctrlKey && !e.altKey,
      action: () => deps.toggleLyricsFullscreen(),
      enabled: () => deps.getCurrentTrack() !== null,
    },

    // ── List Navigation — display-only ──
    {
      id: 'listnav.down',
      category: ShortcutCategory.ListNav,
      keys: { mac: 'J', default: 'J' },
      description: 'Move down in list',
      // TODO: Implement when list keyboard navigation is added
      matches: () => false,
    },
    {
      id: 'listnav.up',
      category: ShortcutCategory.ListNav,
      keys: { mac: 'K', default: 'K' },
      description: 'Move up in list',
      // TODO: Implement when list keyboard navigation is added
      matches: () => false,
    },
    {
      id: 'listnav.open',
      category: ShortcutCategory.ListNav,
      keys: { mac: '↵', default: 'Enter' },
      description: 'Open / Play selected item',
      // TODO: Implement when list keyboard navigation is added
      matches: () => false,
    },
    {
      id: 'listnav.append-queue',
      category: ShortcutCategory.ListNav,
      keys: { mac: '⇧↵', default: 'Shift+Enter' },
      description: 'Append to queue',
      // TODO: Implement when list keyboard navigation is added
      matches: () => false,
    },
  ]
}

// ── Media type entries (Cmd+1-6, display-only) ──

const MEDIA_TYPE_LABELS = [
  { id: 'media.music', label: 'Music', num: 1 },
  { id: 'media.movies', label: 'Movies', num: 2 },
  { id: 'media.tv', label: 'TV Shows', num: 3 },
  { id: 'media.podcasts', label: 'Podcasts', num: 4 },
  { id: 'media.concerts', label: 'Concerts', num: 5 },
  { id: 'media.ebooks', label: 'Ebooks', num: 6 },
]

function createMediaTypeEntries(): ShortcutEntry[] {
  return MEDIA_TYPE_LABELS.map(({ id, label, num }) => ({
    id,
    category: ShortcutCategory.MediaType,
    keys: { mac: `⌘${num}`, default: `Ctrl+${num}` },
    description: label,
    // Display-only: useMediaShortcuts owns the keydown listener
    matches: () => false,
  }))
}

// ── View mode entries (1-6, display-only) ──

const VIEW_MODE_LABELS = [
  { id: 'viewmode.grid', label: 'Grid view', num: 1 },
  { id: 'viewmode.list', label: 'List view', num: 2 },
  { id: 'viewmode.columns', label: 'Column browser', num: 3 },
  { id: 'viewmode.timeline', label: 'Timeline view', num: 4 },
  { id: 'viewmode.activity', label: 'Activity feed', num: 5 },
  { id: 'viewmode.discover', label: 'Discover view', num: 6 },
]

function createViewModeEntries(): ShortcutEntry[] {
  return VIEW_MODE_LABELS.map(({ id, label, num }) => ({
    id,
    category: ShortcutCategory.ViewMode,
    keys: { mac: `${num}`, default: `${num}` },
    description: label,
    // Display-only: ViewModeSwitcher owns the keydown listener
    matches: () => false,
  }))
}
