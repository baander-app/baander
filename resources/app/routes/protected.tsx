import { RootLayout } from '@/layouts/root-layout/root-layout.tsx';
import { Navigate, Outlet, RouteObject } from 'react-router-dom';
import { EchoContextProvider } from '@/providers/echo-provider.tsx';
import { Suspense } from 'react';
import { LibraryMusicRoutes } from '@/features/library-music/routes/_routes.tsx';
import { DashboardLayout } from '@/layouts/dashboard-layout/dasbhard-layout.tsx';
import { AccountRoutes } from '@/features/account/routes/routes.tsx';
import { DashboardRoutes } from '@/features/dashboard/routes.tsx';
import { AudioPlayerContextProvider } from '@/features/library-music-player/providers/audio-player-provider.tsx';
import { UserSettingsRoutes } from '@/features/feature-user-settings/routes/routes.tsx';

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

export const protectedRoutes: RouteObject[] = [
  {
    path: '/',
    element: <App/>,
    children: [
      {
        path: '/library/:library/*',
        element: <LibraryMusicRoutes/>,
      },
      {
        path: '/account/*',
        element: <AccountRoutes/>,
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
];
