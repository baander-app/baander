/**
 * TVUsersPage -- user management admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVUsersPage() {
  return (
    <TVAdminShell title="Users" breadcrumb="Admin / Users">
      <Text style={{ padding: 20 }}>User management coming soon.</Text>
    </TVAdminShell>
  );
}
