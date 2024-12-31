import { Route, Routes } from 'react-router-dom';
import { LibrariesList } from '@/features/dashboard/libraries/libraries-list.tsx';
import { LibrariesNew } from '@/features/dashboard/libraries/libraries-new.tsx';
import { LogViewer } from '@/features/dashboard/routes/log-viewer.tsx';
import { QueueMonitor } from '@/features/dashboard/queue-monitor/queue-monitor.tsx';
import { UsersList } from '@/features/dashboard/users/users-list.tsx';
import { Php } from '@/features/dashboard/system-info/php.tsx';

export const DashboardRoutes = () => {
  return (
    <Routes>
      <Route path="libraries/list" element={<LibrariesList/>}/>
      <Route path="libraries/new" element={<LibrariesNew/>} />

      <Route path="system/log-viewer" element={<LogViewer/>}/>
      <Route path="system/queue-monitor" element={<QueueMonitor />}/>
      <Route path="system/php" element={<Php />} />

      <Route path="users/list" element={<UsersList />} />
    </Routes>
  );
};