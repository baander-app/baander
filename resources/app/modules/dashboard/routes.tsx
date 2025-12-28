import { Navigate, Route, Routes } from 'react-router-dom';
import { LibrariesList } from '@/app/modules/dashboard/libraries/libraries-list.tsx';
import { LibrariesNew } from '@/app/modules/dashboard/libraries/libraries-new.tsx';
import { QueueMonitor } from '@/app/modules/dashboard/queue-monitor/queue-monitor.tsx';
import { UsersList } from '@/app/modules/dashboard/users/users-list.tsx';
import { Php } from '@/app/modules/dashboard/system-info/php.tsx';
import { DashboardHome } from '@/app/modules/dashboard/dashboard-home/dashboard-home.tsx';
import { MusicTasks } from '@/app/modules/dashboard/music/MusicTasks.tsx';
import Logs from '@/app/modules/dashboard/logs/logs.tsx';

export const DashboardRoutes = () => {
  return (
    <Routes>
      <Route path="/home" element={<DashboardHome/>}/>
      <Route path="libraries/list" element={<LibrariesList/>}/>
      <Route path="libraries/new" element={<LibrariesNew/>}/>

      <Route path="music/tasks" element={<MusicTasks />} />

      <Route path="system/log-viewer" element={<Logs />}/>
      <Route path="system/queue-monitor" element={<QueueMonitor/>}/>
      <Route path="system/php" element={<Php/>}/>

      <Route path="users/list" element={<UsersList/>}/>

      <Route path="*" element={<Navigate to="/home"/>}/>
    </Routes>
  );
};