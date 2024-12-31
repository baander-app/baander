import { Route, Routes } from 'react-router-dom';
import { LibrariesList } from '@/features/dashboard/libraries/libraries-list.tsx';
import { LibrariesNew } from '@/features/dashboard/libraries/libraries-new.tsx';
import { LogViewer } from '@/features/dashboard/routes/log-viewer.tsx';
import { QueueMonitor } from '@/features/dashboard/queue-monitor/queue-monitor.tsx';
import { ApiDocs } from '@/features/dashboard/routes/api-docs.tsx';
import { UsersList } from '@/features/dashboard/users/users-list.tsx';

export const DashboardRoutes = () => {
  return (
    <Routes>
      <Route path="docs/api" element={<ApiDocs/>}></Route>
      <Route path="libraries/list" element={<LibrariesList/>}></Route>
      <Route path="libraries/new" element={<LibrariesNew/>}></Route>
      <Route path="log-viewer" element={<LogViewer/>}/>
      <Route path="queue-monitor" element={<QueueMonitor />}/>

      <Route path="users/list" element={<UsersList />} />
    </Routes>
  );
};