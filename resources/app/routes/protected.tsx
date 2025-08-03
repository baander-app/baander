import { RootLayout } from '@/layouts/root-layout/root-layout.tsx';
import { Navigate, Outlet, RouteObject } from 'react-router-dom';
import { Suspense } from 'react';
import { LibraryMusicRoutes } from '@/modules/library-music/routes/_routes.tsx';
import { DashboardLayout } from '@/layouts/dashboard-layout/dashboard-layout.tsx';
import { DashboardRoutes } from '@/modules/dashboard/routes.tsx';
import { AudioPlayerContextProvider } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { UserSettingsRoutes } from '@/modules/user-settings/routes.tsx';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryMoviesRoutes } from '@/modules/library-movies/routes/_routes.tsx';
import { LibraryType } from '@/models/library-type.ts';
import { Overview } from '@/modules/overview/overview.tsx';
import { LibraryMusicPlaylistsRoutes } from '@/modules/library-music-playlists/_routes.tsx';
import { useLibraryShow } from '@/libs/api-client/gen/endpoints/library/library.ts';

const App = () => {
  return (
    <AudioPlayerContextProvider>
      <RootLayout>
        <Suspense>
          <Outlet/>
        </Suspense>
      </RootLayout>
    </AudioPlayerContextProvider>
  );
};

const DashboardApp = () => {
  return (
    <DashboardLayout>
      <Suspense>
        <Outlet/>
      </Suspense>
    </DashboardLayout>
  );
};

const LibraryRoutes = () => {
  const { library } = usePathParam<{ library: string }>();

  const { data: libraryData, failureReason, isLoading } = useLibraryShow(library);

  if (isLoading) return <div>Loading...</div>;
  if (failureReason) return <div>Error: {failureReason.message}</div>;

  switch (libraryData?.type) {
    case LibraryType.Music:
      return <LibraryMusicRoutes/>;
    case LibraryType.Movie:
      return <LibraryMoviesRoutes/>;
    default:
      return <Navigate to="/"/>;
  }
};

export const protectedRoutes: RouteObject[] = [
  {
    path: '/',
    element: <App/>,
    children: [
      {
        path: '/',
        element: <Overview title="Albums" />
      },
      {
        path: '/library/:library/*',
        element: <LibraryRoutes/>,
      },
      {
        path: '/playlists/music/*',
        element: <LibraryMusicPlaylistsRoutes />
      },
      {
        path: '/user/settings/*',
        element: <UserSettingsRoutes/>,
      },
      {
        path: '*',
        element: <Navigate to="/"/>,
      },
    ],
  },
  {
    path: '/dashboard',
    element: <DashboardApp/>,
    children: [
      {
        path: '*',
        element: <DashboardRoutes/>,
      },
    ],
  },
  {
    path: '/*',
    element: <Navigate to="/"/>,
  },
];
