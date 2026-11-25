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
      "src/features/playlist/components/SharePlaylistDialog.tsx#useGetPlaylistShow:generated",
      "src/features/playlist/hooks/use-playlist-index.ts#useQuery:getGetPlaylistIndexQueryKey()",
      "src/features/playlist/hooks/use-playlist-index.ts#AXIOS_INSTANCE.get:'/api/playlists/'",
      "src/features/playlist/pages/PlaylistDetailPage.tsx#useGetPlaylistShow:generated",
    ],
    coverageVerification: ["tests/live-updates/coverage.test.ts"],
    behaviorVerification: { unitProofs: ["tests/live-updates/collections.test.tsx"] },
  },
] as const satisfies readonly LiveCoverageDescriptor[]
