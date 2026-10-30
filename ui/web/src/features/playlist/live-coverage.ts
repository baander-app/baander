import type { LiveCoverageDescriptor } from '@/shared/live/coverage-types'

export const playlistLiveCoverage = [
  {
    id: "playlist.playlists",
    family: "playlists",
    policy: {
      kind: "stored",
      reason: "Playlist membership, metadata, collaboration and derived statistics affect index, detail and share dialogs.",
    },
    audiences: ["playlist", "user"],
    sourceTables: [
      "playlists",
      "playlist_song",
      "playlist_collaborators",
      "playlist_statistics",
      "songs",
      "albums",
      "artists",
      "images",
    ],
    deploymentTables: [],
    sources: ["[ `/api/playlists/` ]", "[ `/api/playlists/${publicId}` ]"],
    keyVariants: ["[ `/api/playlists/` ]", "[ `/api/playlists/${publicId}` ]"],
    dependentFamilies: [],
    mirrors: [],
    responseCaches: ["authoritative-read"],
    consumers: [
      "src/features/playlist/components/AddToPlaylistDialog.tsx#useGetPlaylistIndex:generated",
      "src/features/playlist/components/SharePlaylistDialog.tsx#useGetPlaylistShow:generated",
      "src/features/playlist/pages/PlaylistDetailPage.tsx#useGetPlaylistShow:generated",
      "src/features/playlist/pages/PlaylistsPage.tsx#useGetPlaylistIndex:generated",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: "pending-adoption",
  },
] as const satisfies readonly LiveCoverageDescriptor[]
