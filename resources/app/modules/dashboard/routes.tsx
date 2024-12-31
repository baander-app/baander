import { Route, Routes } from 'react-router-dom';
import { LibrariesList } from '@/modules/dashboard/libraries/libraries-list.tsx';
import { LibrariesNew } from '@/modules/dashboard/libraries/libraries-new.tsx';
import { LogViewer } from '@/modules/dashboard/routes/log-viewer.tsx';
import { QueueMonitor } from '@/modules/dashboard/queue-monitor/queue-monitor.tsx';
import { UsersList } from '@/modules/dashboard/users/users-list.tsx';
import { Php } from '@/modules/dashboard/system-info/php.tsx';

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