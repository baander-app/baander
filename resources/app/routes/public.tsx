import { lazyImport } from '@/hooks/lazy-import';
import { BareLayout } from '@/layouts/bare-layout';
import { Outlet } from 'react-router-dom';
import { AuthorizeRoutes } from '@/features/authorize/routes.tsx';

const {AuthRoutes} = lazyImport(() => import('@/features/auth/routes'), 'AuthRoutes');

const App = () => {
  return (
    <BareLayout>
      <Outlet/>
    </BareLayout>
  );
};

export const publicRoutes = [
  {
    path: '/*',
    element: <App/>,
    children: [
      {path: '', element: <AuthRoutes/>},
      {path: 'authorize/*', element: <AuthorizeRoutes/>},
    ],
  },
];
