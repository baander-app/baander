/**
 * TVActivityPage -- activity log admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVActivityPage() {
  return (
    <TVAdminShell title="Activity" breadcrumb="Admin / Analytics">
      <Text style={{ padding: 20 }}>Activity log coming soon.</Text>
    </TVAdminShell>
  );
}
