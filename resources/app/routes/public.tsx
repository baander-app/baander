import { lazyImport } from '@/utils/lazy-import.ts';
import { BareLayout } from '@/layouts/bare-layout/bare-layout.tsx';
import { Outlet } from 'react-router-dom';
import { AuthorizeRoutes } from '@/modules/authorize/routes.tsx';

const {AuthRoutes} = lazyImport(() => import('@/modules/auth/routes'), 'AuthRoutes');

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
      {path: '*', element: <AuthRoutes/>},
    ],
  },
];
