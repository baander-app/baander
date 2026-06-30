/**
 * TVLyricsAdminPage -- lyrics management admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVLyricsAdminPage() {
  return (
    <TVAdminShell title="Lyrics" breadcrumb="Admin / Content">
      <Text style={{ padding: 20 }}>Lyrics management coming soon.</Text>
    </TVAdminShell>
  );
}
