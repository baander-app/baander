/**
 * TVTranscodePage -- transcode management admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVTranscodePage() {
  return (
    <TVAdminShell title="Transcode" breadcrumb="Admin / Content">
      <Text style={{ padding: 20 }}>Transcode management coming soon.</Text>
    </TVAdminShell>
  );
}
