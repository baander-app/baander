import { publicRoutes } from '@/routes/public';
import { useRoutes } from 'react-router-dom';
import { protectedRoutes } from '@/routes/protected';
import { useSelector } from 'react-redux';
import { selectIsAuthenticated } from '@/store/users/auth-slice.ts';

export function AppRoutes() {
  const isAuthenticated = useSelector(selectIsAuthenticated);

  // see https://github.com/alan2207/bulletproof-react/blob/master/src/routes/index.tsx
  const routes = isAuthenticated ? protectedRoutes : publicRoutes;

  const element = useRoutes([...routes]);

  return <>{element}</>;
}
