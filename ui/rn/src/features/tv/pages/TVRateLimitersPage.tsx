/**
 * TVRateLimitersPage -- rate limiter configuration admin page.
 */

import React from 'react';
import { Text } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';
import { TVTable } from '@baander/shared';

export function TVRateLimitersPage() {
  return (
    <TVAdminShell title="Rate Limiters" breadcrumb="Admin / Operations">
      <TVTable>
        <Text style={{ padding: 20 }}>Rate limiter configuration coming soon.</Text>
      </TVTable>
    </TVAdminShell>
  );
}
