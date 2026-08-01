/**
 * TVMetadataPage -- metadata management admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVMetadataPage() {
  return (
    <TVAdminShell title="Metadata" breadcrumb="Admin / Content">
      <Text style={{ padding: 20 }}>Metadata management coming soon.</Text>
    </TVAdminShell>
  );
}
