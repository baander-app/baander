/**
 * TVAlbumDuplicatesPage -- duplicate album detection admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVAlbumDuplicatesPage() {
  return (
    <TVAdminShell title="Album Duplicates" breadcrumb="Admin / Content">
      <Text style={{ padding: 20 }}>Duplicate album detection coming soon.</Text>
    </TVAdminShell>
  );
}
