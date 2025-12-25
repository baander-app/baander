import { RootLayout } from '@/app/layouts/root-layout/root-layout.tsx';
import { Navigate, Outlet, RouteObject } from 'react-router-dom';
import { Suspense } from 'react';
import { LibraryMusicRoutes } from '@/app/modules/library-music/routes/_routes.tsx';
import { DashboardLayout } from '@/app/layouts/dashboard-layout/dashboard-layout.tsx';
import { DashboardRoutes } from '@/app/modules/dashboard/routes.tsx';
import { UserSettingsRoutes } from '@/app/modules/user-settings/routes.tsx';
import { usePathParam } from '@/app/hooks/use-path-param.ts';
import { LibraryMoviesRoutes } from '@/app/modules/library-movies/routes/_routes.tsx';
import { LibraryType } from '@/app/models/library-type.ts';
import { Overview } from '@/app/modules/overview/overview.tsx';
import { LibraryMusicPlaylistsRoutes } from '@/app/modules/library-music-playlists/_routes.tsx';
import { useLibraryShowSuspense } from '@/app/libs/api-client/gen/endpoints/library/library.ts';
import { ErrorRoutes } from '@/app/modules/error/routes/_routes.tsx';

const App = () => {
  return (
    <RootLayout>
      <Suspense>
        <Outlet/>
      </Suspense>
    </RootLayout>
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

  const { data } = useLibraryShowSuspense(library);

  switch (data.type) {
    case LibraryType.Music:
      return <LibraryMusicRoutes/>;
    case LibraryType.Movie:
      return <LibraryMoviesRoutes/>;
    default:
      return <Navigate to="/error/not-found"/>;
  }
};


export const protectedRoutes: RouteObject[] = [
  {
    path: '/',
    element: <App/>,
    children: [
      {
        path: '/',
        element: <Overview title="Albums"/>,
      },
      {
        path: '/library/:library/*',
        element: <LibraryRoutes/>,
      },
      {
        path: '/playlists/music/*',
        element: <LibraryMusicPlaylistsRoutes/>,
      },
      {
        path: '/user/settings/*',
        element: <UserSettingsRoutes/>,
      },
      {
        path: '/error/*',
        element: <ErrorRoutes/>,
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
    path: '/error/*',
    element: <ErrorRoutes/>,
  },
  {
    path: '/*',
    element: <Navigate to="/"/>,
  },
];
