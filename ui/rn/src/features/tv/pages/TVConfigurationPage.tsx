/**
 * TVConfigurationPage -- server configuration admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVConfigurationPage() {
  return (
    <TVAdminShell title="Configuration" breadcrumb="Admin / Settings">
      <Text style={{ padding: 20 }}>Server configuration coming soon.</Text>
    </TVAdminShell>
  );
}
