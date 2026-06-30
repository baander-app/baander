import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export interface ColumnConfig {
  id: string
  label: string
  field: string
  defaultVisible: boolean
}

export const ALL_COLUMNS: ColumnConfig[] = [
  { id: '#', label: '#', field: 'index', defaultVisible: true },
  { id: 'title', label: 'Title', field: 'title', defaultVisible: true },
  { id: 'artist', label: 'Artist', field: 'artistName', defaultVisible: true },
  { id: 'album', label: 'Album', field: 'albumName', defaultVisible: true },
  { id: 'year', label: 'Year', field: 'year', defaultVisible: true },
  { id: 'genre', label: 'Genre', field: 'genre', defaultVisible: false },
  { id: 'duration', label: 'Duration', field: 'length', defaultVisible: true },
  { id: 'bitrate', label: 'Bitrate', field: 'bitrate', defaultVisible: false },
  { id: 'format', label: 'Format', field: 'format', defaultVisible: false },
  { id: 'createdAt', label: 'Date Added', field: 'createdAt', defaultVisible: false },
]

const DEFAULT_VISIBLE = ALL_COLUMNS.filter((c) => c.defaultVisible).map((c) => c.id)
const DEFAULT_ORDER = ALL_COLUMNS.map((c) => c.id)

export const DEFAULT_WIDTHS: Record<string, number> = {
  '#': 40,
  title: 300,
  artist: 150,
  album: 150,
  year: 60,
  genre: 120,
  duration: 64,
  bitrate: 90,
  format: 80,
  createdAt: 120,
}

export interface ListColumnState {
  visibleColumns: string[]
  columnOrder: string[]
  columnWidths: Record<string, number>
  toggleColumn: (column: string) => void
  reorderColumns: (order: string[]) => void
  setColumnWidth: (columnId: string, width: number) => void
}

export const useListColumnStore = create<ListColumnState>()(
  persist(
    (set) => ({
      visibleColumns: DEFAULT_VISIBLE,
      columnOrder: DEFAULT_ORDER,
      columnWidths: { ...DEFAULT_WIDTHS },

      toggleColumn: (column) =>
        set((state) => {
          const isVisible = state.visibleColumns.includes(column)
          return {
            visibleColumns: isVisible
              ? state.visibleColumns.filter((c) => c !== column)
              : [...state.visibleColumns, column],
          }
        }),

      reorderColumns: (order) => set({ columnOrder: order }),

      setColumnWidth: (columnId, width) =>
        set((state) => ({
          columnWidths: { ...state.columnWidths, [columnId]: width },
        })),
    }),
    {
      name: 'baander-list-columns',
      version: 2,
      partialize: (state) => ({
        visibleColumns: state.visibleColumns,
        columnOrder: state.columnOrder,
        columnWidths: state.columnWidths,
      }),
      migrate: (persisted, version) => {
        if (version < 2) {
          return {
            ...(persisted as Record<string, unknown>),
            columnWidths: { ...DEFAULT_WIDTHS },
          }
        }
        return persisted
      },
    },
  ),
)
