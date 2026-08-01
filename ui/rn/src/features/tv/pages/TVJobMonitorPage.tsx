/**
 * TVJobMonitorPage -- job monitor admin page.
 */

import React from 'react';
import { View, Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';
import { TVJobList } from '../components/TVJobList';
import { useActiveJobs } from '@/features/admin/hooks/useActiveJobs';

export function TVJobMonitorPage() {
  const { data: jobs } = useActiveJobs();

  return (
    <TVAdminShell title="Job Monitor" breadcrumb="Admin / Monitoring">
      <TVJobList jobs={jobs} />
    </TVAdminShell>
  );
}
