/**
 * TVGenresPage (admin) -- genre management admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVGenresAdminPage() {
  return (
    <TVAdminShell title="Genres" breadcrumb="Admin / Content">
      <Text style={{ padding: 20 }}>Genre management coming soon.</Text>
    </TVAdminShell>
  );
}
