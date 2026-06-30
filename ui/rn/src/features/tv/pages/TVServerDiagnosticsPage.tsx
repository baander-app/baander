/**
 * TVServerDiagnosticsPage -- server diagnostics admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVServerDiagnosticsPage() {
  return (
    <TVAdminShell title="Server Diagnostics" breadcrumb="Admin / Operations">
      <Text style={{ padding: 20 }}>Server diagnostics coming soon.</Text>
    </TVAdminShell>
  );
}
