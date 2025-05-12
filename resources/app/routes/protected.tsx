import { RootLayout } from '@/layouts/root-layout/root-layout.tsx';
import { Navigate, Outlet, RouteObject } from 'react-router-dom';
import { EchoContextProvider } from '@/providers/echo-provider.tsx';
import { Suspense } from 'react';
import { LibraryMusicRoutes } from '@/modules/library-music/routes/_routes.tsx';
import { DashboardLayout } from '@/layouts/dashboard-layout/dashboard-layout.tsx';
import { DashboardRoutes } from '@/modules/dashboard/routes.tsx';
import { AudioPlayerContextProvider } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { UserSettingsRoutes } from '@/modules/user-settings/routes.tsx';
import { useLibraryServiceGetApiLibrariesBySlug } from '@/api-client/queries';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryMoviesRoutes } from '@/modules/library-movies/routes/_routes.tsx';
import { LibraryType } from '@/models/library-type.ts';
import { Overview } from '@/modules/overview/overview.tsx';

const App = () => {
  return (
    <EchoContextProvider>
      <AudioPlayerContextProvider>
        <RootLayout>
          <Suspense>
            <Outlet/>
          </Suspense>
        </RootLayout>
      </AudioPlayerContextProvider>
    </EchoContextProvider>
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

  const { data: libraryData, failureReason, isLoading } = useLibraryServiceGetApiLibrariesBySlug({ slug: library });

  if (isLoading) return <div>Loading...</div>;
  if (failureReason) return <div>Error: {failureReason as string}</div>;

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
        element: <Overview />
      },
      {
        path: '/library/:library/*',
        element: <LibraryRoutes/>,
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
