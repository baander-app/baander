import {
  Home,
  Disc,
  Mic2,
  Music,
  Tag,
  ListMusic,
  Star,
  Sparkles,
  Radio,
  Play,
  TrendingUp,
  MapPin,
  Book,
  BookOpen,
  Layers,
  Settings,
  SlidersHorizontal,
  Search,
  Clock,
  Film,
  Tv,
  Podcast,
  type LucideIcon,
} from 'lucide-react'

/**
 * Maps sidebar item icon keys to Lucide components.
 * Shared between SidebarSelector, SidebarSection, and SidebarEditor.
 */
const SIDEBAR_ICONS: Record<string, LucideIcon> = {
  home: Home,
  disc: Disc,
  'mic-2': Mic2,
  music: Music,
  tag: Tag,
  'list-music': ListMusic,
  star: Star,
  sparkles: Sparkles,
  radio: Radio,
  play: Play,
  'trending-up': TrendingUp,
  'map-pin': MapPin,
  book: Book,
  'book-open': BookOpen,
  layers: Layers,
  settings: Settings,
  'sliders-horizontal': SlidersHorizontal,
  search: Search,
  clock: Clock,
  film: Film,
  tv: Tv,
  podcast: Podcast,
}

export function getSidebarIcon(key: string): LucideIcon {
  return SIDEBAR_ICONS[key] ?? Disc
}

export { SIDEBAR_ICONS }
