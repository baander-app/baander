import { Route, Routes } from 'react-router-dom';
import { LibrariesList } from '@/features/dashboard/routes/libraries-list.tsx';
import { LogViewer } from '@/features/dashboard/routes/log-viewer.tsx';
import { QueueMonitor } from '@/features/dashboard/routes/queue-monitor.tsx';
import { ApiDocs } from '@/features/dashboard/routes/api-docs.tsx';

export const DashboardRoutes = () => {
  return (
    <Routes>
      <Route path="docs/api" element={<ApiDocs/>}></Route>
      <Route path="libraries/list" element={<LibrariesList/>}></Route>
      <Route path="log-viewer" element={<LogViewer/>}/>
      <Route path="queue-monitor" element={<QueueMonitor />}/>
    </Routes>
  );
};