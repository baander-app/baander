import { RootLayout } from '@/layouts/root-layout/root-layout.tsx';
import { Navigate, Outlet, RouteObject } from 'react-router-dom';
import { EchoContextProvider } from '@/providers/echo-provider.tsx';
import { Suspense } from 'react';
import { LibraryMusicRoutes } from '@/features/library-music/routes/routes.tsx';
import { DashboardLayout } from '@/layouts/dashboard-layout/dasbhard-layout.tsx';
import { DashboardRoutes } from '@/features/dashboard/routes/routes.tsx';
import { AccountRoutes } from '@/features/account/routes/routes.tsx';

const App = () => {
  return (
    <RootLayout>
      <EchoContextProvider>
        <Suspense>
          <Outlet/>
        </Suspense>
      </EchoContextProvider>
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
        element: <AccountRoutes />
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
