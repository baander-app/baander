import styled from 'styled-components'
import { type RouteObject, Navigate, useParams } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { ProtectedRoute } from '../auth/components/ProtectedRoute'
import { AdminRoute } from '../admin/components/layout/AdminRoute'
import { LoginPage } from '../auth/pages/LoginPage'
import { RegisterPage } from '../auth/pages/RegisterPage'
import { AlbumsPage } from '../catalog/pages/AlbumsPage'
import { AlbumDetailPage } from '../catalog/pages/AlbumDetailPage'
import { ArtistDetailPage } from '../catalog/pages/ArtistDetailPage'
import { ArtistsPage } from '../catalog/pages/ArtistsPage'
import { SongsPage } from '../catalog/pages/SongsPage'
import { CatalogShell } from '../catalog/CatalogShell'
import { GenresPage } from '../catalog/pages/GenresPage'
import { SearchPage } from '../catalog/pages/SearchPage'
import { HomePage } from '../catalog/pages/HomePage'
import { MoviesHomePage } from '../movies/pages/MoviesHomePage'
import { MoviesBrowsePage } from '../catalog/pages/MoviesBrowsePage'
import { MovieDetailPage } from '../catalog/pages/MovieDetailPage'
import { TVHomePage } from '../tv/pages/TVHomePage'
import { PodcastsHomePage } from '../podcasts/pages/PodcastsHomePage'
import { ConcertsHomePage } from '../concerts/pages/ConcertsHomePage'
import { EbooksHomePage } from '../ebooks/pages/EbooksHomePage'
import { PlaylistsPage } from '../playlist/pages/PlaylistsPage'
import { PlaylistDetailPage } from '../playlist/pages/PlaylistDetailPage'
import { EqualizerPage } from '../equalizer/pages/EqualizerPage'
import { SettingsPage } from '../settings/pages/SettingsPage'
import { RadioPage } from '../radio/pages/RadioPage'
import { AdminShell } from '../admin/components/layout/AdminShell'
import { AdminOverviewPage } from '../admin/pages/AdminOverviewPage'
import { AdminLibraryPage } from '../admin/pages/AdminLibraryPage'
import { LibraryDetailPage } from '../library/pages/LibraryDetailPage'
import { AdminSecurityPage } from '../admin/pages/AdminSecurityPage'
import { AdminMediaPage } from '../admin/pages/AdminMediaPage'
import { RadioAdminPage } from '../admin/pages/RadioAdminPage'
import { AdminAnalyticsPage } from '../admin/pages/AdminAnalyticsPage'
import { AdminSettingsPage } from '../admin/pages/AdminSettingsPage'

const PlaceholderContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const PlaceholderTitle = styled.p`
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
  margin: 0;
`

const PlaceholderSubtitle = styled.p`
  font-size: 0.875rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
  margin: 0;
`

/** Redirect component that forwards route params */
function ParamRedirect({ to }: { to: string }) {
  const params = useParams()
  let target = to
  Object.entries(params).forEach(([key, value]) => {
    if (value) target = target.replace(`:${key}`, value)
  })
  return <Navigate to={target} replace />
}

/** Placeholder home page for non-music media types */
function MediaPlaceholder({ title }: { title: string }) {
  return (
    <PlaceholderContainer>
      <PlaceholderTitle>{title}</PlaceholderTitle>
      <PlaceholderSubtitle>Coming soon</PlaceholderSubtitle>
    </PlaceholderContainer>
  )
}

export const publicRoutes: RouteObject[] = [
  { path: '/login', element: <LoginPage /> },
  { path: '/register', element: <RegisterPage /> },
]

export const protectedRoutes: RouteObject[] = [
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppShell />,
        children: [
          // Root redirect
          { path: '/', element: <Navigate to="/music" replace /> },

          // -- Music routes --
          { path: '/music', element: <HomePage /> },
          { path: '/music/albums', element: <AlbumsPage /> },
          { path: '/music/albums/:publicId', element: <AlbumDetailPage /> },
          { path: '/music/artists', element: <ArtistsPage /> },
          { path: '/music/artists/:publicId', element: <ArtistDetailPage /> },
          { path: '/music/songs', element: <SongsPage /> },
          { path: '/music/genres', element: <GenresPage /> },
          { path: '/music/browse', element: <CatalogShell /> },
          { path: '/music/playlists', element: <PlaylistsPage /> },
          { path: '/music/playlists/:publicId', element: <PlaylistDetailPage /> },
          { path: '/music/radio', element: <RadioPage /> },

          // -- Movies routes --
          { path: '/movies', element: <MoviesHomePage /> },
          { path: '/movies/browse', element: <MoviesBrowsePage /> },
          { path: '/movies/:publicId', element: <MovieDetailPage /> },

          // -- TV routes --
          { path: '/tv', element: <TVHomePage /> },
          { path: '/tv/browse', element: <MediaPlaceholder title="TV Browser" /> },

          // -- Podcasts routes --
          { path: '/podcasts', element: <PodcastsHomePage /> },

          // -- Concerts routes --
          { path: '/concerts', element: <ConcertsHomePage /> },

          // -- Ebooks routes --
          { path: '/ebooks', element: <EbooksHomePage /> },

          // -- Global routes --
          { path: '/search', element: <SearchPage /> },
          { path: '/equalizer', element: <EqualizerPage /> },
          { path: '/settings', element: <SettingsPage /> },

          // -- Backward-compat redirects --
          { path: '/albums', element: <Navigate to="/music/albums" replace /> },
          { path: '/albums/:publicId', element: <ParamRedirect to="/music/albums/:publicId" /> },
          { path: '/artists', element: <Navigate to="/music/artists" replace /> },
          { path: '/artists/:publicId', element: <ParamRedirect to="/music/artists/:publicId" /> },
          { path: '/songs', element: <Navigate to="/music/songs" replace /> },
          { path: '/genres', element: <Navigate to="/music/genres" replace /> },
          { path: '/browse', element: <Navigate to="/music/browse" replace /> },
          { path: '/playlists', element: <Navigate to="/music/playlists" replace /> },
          { path: '/radio', element: <Navigate to="/music/radio" replace /> },

          // Catch-all
          { path: '*', element: <Navigate to="/music" replace /> },
        ],
      },
      {
        element: <AdminRoute />,
        children: [
          {
            element: <AdminShell />,
            children: [
              { path: '/admin', element: <AdminOverviewPage /> },
              { path: '/admin/library', element: <AdminLibraryPage /> },
              { path: '/admin/library/:id', element: <LibraryDetailPage /> },
              { path: '/admin/security', element: <AdminSecurityPage /> },
              { path: '/admin/media', element: <AdminMediaPage /> },
              { path: '/admin/radio', element: <RadioAdminPage /> },
              { path: '/admin/analytics', element: <AdminAnalyticsPage /> },
              { path: '/admin/settings', element: <AdminSettingsPage /> },

              // Backward-compat redirects
              { path: '/admin/monitor', element: <Navigate to="/admin?tab=jobs" replace /> },
              { path: '/admin/scheduler', element: <Navigate to="/admin?tab=scheduler" replace /> },
              { path: '/admin/rate-limiters', element: <Navigate to="/admin?tab=rate-limits" replace /> },
              { path: '/admin/diagnostics', element: <Navigate to="/admin?tab=diagnostics" replace /> },
              { path: '/admin/configuration', element: <Navigate to="/admin/settings?tab=config-health" replace /> },
              { path: '/admin/libraries', element: <Navigate to="/admin/library" replace /> },
              { path: '/admin/users', element: <Navigate to="/admin/security?tab=users" replace /> },
              { path: '/admin/metadata', element: <Navigate to="/admin/library?tab=metadata" replace /> },
              { path: '/admin/genres', element: <Navigate to="/admin/library?tab=genres" replace /> },
              { path: '/admin/duplicates', element: <Navigate to="/admin/library?tab=duplicates" replace /> },
              { path: '/admin/lyrics', element: <Navigate to="/admin/library?tab=lyrics" replace /> },
              { path: '/admin/transcode', element: <Navigate to="/admin/media?tab=transcode" replace /> },
              { path: '/admin/activity', element: <Navigate to="/admin/analytics?tab=activity" replace /> },
              { path: '/admin/recommendations', element: <Navigate to="/admin/analytics?tab=recommendations" replace /> },
              { path: '/admin/login-blocks', element: <Navigate to="/admin/security?tab=login-blocks" replace /> },
              { path: '/admin/media-storage', element: <Navigate to="/admin/media?tab=storage" replace /> },
            ],
          },
        ],
      },
    ],
  },
]

export const routes: RouteObject[] = [...publicRoutes, ...protectedRoutes]
