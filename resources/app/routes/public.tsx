import { BareLayout } from '@/app/layouts/bare-layout/bare-layout.tsx';
import { Navigate, Outlet } from 'react-router-dom';
import { AuthorizeRoutes } from '@/app/modules/authorize/routes.tsx';
import Login from '@/app/modules/auth/routes/login.tsx';
import { AuthRoutes } from '@/app/modules/auth/routes/_routes.tsx';

const App = () => {
  return (
    <BareLayout>
      <Outlet/>
    </BareLayout>
  );
};

export const publicRoutes = [
  {
    path: '/',
    element: <App/>,
    children: [
      { path: '', element: <Login/> },
      { path: 'auth/*', element: <AuthRoutes/> },
      { path: 'authorize/*', element: <AuthorizeRoutes/> },
      { path: '*', element: <Navigate to="/"/> },
    ],
  },
];
