/**
 * TVRecommendationsPage -- recommendation settings admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';

export function TVRecommendationsPage() {
  return (
    <TVAdminShell title="Recommendations" breadcrumb="Admin / Analytics">
      <Text style={{ padding: 20 }}>Recommendation settings coming soon.</Text>
    </TVAdminShell>
  );
}
