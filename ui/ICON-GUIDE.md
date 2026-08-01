# Icon Guide

This guide defines the canonical icon-to-concept mapping for the Baander frontend and -- more importantly -- **when** to use icons at all.

**Icon library:** [Lucide React](https://lucide.dev/icons/)

---

## When to Use Icons

**Icons are a visual highlight tool, not a decoration tool.** Their purpose is to help the eye land on the most important options in a menu. Overusing them defeats that purpose.

| Context | Icons? | Why |
|---------|--------|-----|
| Overlay menus (dropdown, context menu) | Yes, up to 3 | Overlays are scanned quickly; icons anchor the eye to key actions |
| Compact/chrome areas (player bar, panel tabs) | Yes | Space is limited; icons replace labels by necessity |
| Static menus (sidebar, admin nav) | No -- text labels | Space is available; text is more scannable at rest. Use icons only for the top 2-3 highest-priority items if at all |
| Buttons with text | Generally no | The label already communicates; an icon adds noise |
| Standalone icon buttons (no label) | Yes | The icon IS the label -- e.g. play/pause, close, volume |

### Rules

1. **Max 3 icons per menu.** If everything is highlighted, nothing is.
2. **Same concept = same icon.** If "favorites" uses `Star` somewhere, it uses `Star` everywhere.
3. **Overlay menus:** icons make great sense. Use them for destructive, primary, or disambiguating actions.
4. **Static menus:** text labels are preferred. Only add icons when space is a genuine luxury.
5. **Check this guide** before importing a new Lucide icon -- the concept may already be assigned.
6. **Import from `lucide-react` directly.** No wrapper components.

---

## Sidebar Icons

The sidebar schemas use string keys mapped in `src/features/layout/schemas/icons.ts`. The sidebar is a **static menu** -- icons are used sparingly here, primarily to visually distinguish media types in the sidebar selector and to mark the current context's home.

### Current Key Mapping

| Key | Component | Semantic Meaning |
|-----|-----------|-----------------|
| `home` | `Home` | Section home / landing |
| `_disc` | `Disc` | Browse / media library root (fallback) |
| `radio` | `Radio` | Radio / live stream |
| `star` | `Star` | Favorites |
| `sparkles` | `Sparkles` | AI recommendations |

These are the only sidebar icons that should have visual weight. The remaining keys in `icons.ts` exist for schema completeness but should not be treated as a mandate to icon-ify every sidebar row.

### Known Inconsistencies (not yet fixed)

- `list-music` used for watchlists, shelves, and subscriptions in non-music contexts -- a generic list icon would be more accurate but this is low priority since sidebar items are text-first
- `mic-2` used for directors and authors -- semantically "creator" but `Mic2` reads as "performer"
- `_disc` is the default fallback for all media types -- no unique icon for Movies, Concerts, or TV Shows

---

## Canonical Concept to Icon Assignments

Use these when an icon IS warranted (overlay menu, compact chrome, standalone button).

### Playback Controls (compact chrome -- always icon-only)

| Concept | Import |
|---------|--------|
| Play | `Play` |
| Pause | `Pause` |
| Skip next | `SkipForward` |
| Skip previous | `SkipBack` |
| Shuffle | `Shuffle` |
| Stop (radio) | `Square` |
| Volume (on) | `Volume2` |
| Volume (muted) | `VolumeX` |

### Core Actions (overlay menus -- pick up to 3)

| Concept | Import |
|---------|--------|
| Add / create | `Plus` |
| Delete / remove | `Trash2` |
| Edit | `Pencil` |
| Favorite / star | `Star` |
| Play item | `Play` |

### Status Indicators (inline badges, not menu items)

| Concept | Import |
|---------|--------|
| Success | `CheckCircle2` |
| Failure | `XCircle` |
| Warning | `AlertTriangle` |
| Loading (inline) | `Loader2` |
| Loading (area) | `Loader` |

### UI Primitives (shared components only)

| Concept | Import | Notes |
|---------|--------|-------|
| Close | `X` | Feature code -- not `XIcon` |
| Search | `Search` | Feature code -- not `SearchIcon` |
| Check / selected | `Check` | Feature code -- not `CheckIcon` |
| Chevron (right) | `ChevronRight` | Feature code -- not `ChevronRightIcon` |
| Chevron (down) | `ChevronDown` | |
| Chevron (left) | `ChevronLeft` | |
| Sort toggle | `ArrowUpDown` | `sort-select.tsx` |
| Drag handle | `GripVertical` | DnD sortable |

> **Do not use `SearchIcon`, `CheckIcon`, `XIcon`, `ChevronRightIcon` in feature code.**
> Those `*Icon` aliases exist only inside shadcn/ui primitives to avoid name collisions.

### Other Assigned Icons

| Concept | Import | Context |
|---------|--------|---------|
| Refresh | `RefreshCw` | Admin pages |
| Download | `Download` | Lyrics export |
| External link | `ExternalLink` | External URLs |
| Undo | `Undo2` | Preference history |
| Back / navigate | `ArrowLeft` | Admin to app |
| Notifications | `Bell` | Bell icon |
| Device | `Monitor` | Device management |
| Lock | `Lock` | Protection metadata |
| Camera | `Camera` | Cover upload |
| Info | `Info` | Context panel tab |
| Playlist/queue | `ListMusic` | Context panel tab |
| Lyrics | `FileText` | Context panel tab |
| Fullscreen | `Maximize2` | Lyrics overlay |
| Exit fullscreen | `Minimize` | Lyrics overlay |
| Missing image | `ImageOff` | Fallback |
| Globe | `Globe` | Country picker |
| Grid view | `LayoutGrid` | View mode switcher |
| List view | `List` | View mode switcher |
| Admin diagnostics | `Stethoscope` | Quick actions |
| Admin CPU/processing | `Cpu` | Transcode page |
| Admin database | `Database` | Metadata page |
| Admin security | `ShieldAlert` | Users page |
| Admin user config | `UserCog` | Users page |
| Admin API key | `KeyRound` | Users page |
| Admin overflow | `MoreHorizontal` | Users page |
| Admin activity | `Activity` | Activity page |
| Tag / genre | `Tag` | Sidebar schema |
| Trending | `TrendingUp` | Sidebar schema |
| Location | `MapPin` | Sidebar schema |
| Layers / series | `Layers` | Sidebar schema |
| Music note | `Music` | Player bar, song items |
| Podcast | `Podcast` | Sidebar schema |
| Film | `Film` | Sidebar schema |
| TV | `Tv` | Sidebar schema |
| Book | `Book` | Sidebar schema |
| Book (open) | `BookOpen` | Sidebar schema |

---

## Adding a New Icon Assignment

1. Is this concept already in the guide? -> Reuse the assigned icon.
2. Is this a sidebar item? -> Add the key to `icons.ts` AND update this guide.
3. Is this for an overlay menu? -> Good use case, but limit to 3 icons per menu.
4. Is this for a static menu? -> Strongly prefer text-only. Do not add an icon.
5. Is it truly new? -> Add it to the correct table, then `icons.ts` if sidebar-relevant.
