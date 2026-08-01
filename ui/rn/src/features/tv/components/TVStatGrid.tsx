/**
 * TVStatGrid -- grid of stat cards for admin dashboard.
 *
 * Displays server metrics in a grid layout.
 */

import React from 'react';
import { View, StyleSheet } from 'react-native';
import { TVStatusCard } from '@baander/shared';
import type { ServerStats } from '@/features/admin/hooks/useAdminStats';
import { tvSpacing } from '../theme/tv-tokens';

export interface TVStatGridProps {
  stats: ServerStats | null;
}

export function TVStatGrid({ stats }: TVStatGridProps) {
  if (!stats) {
    return null;
  }

  return (
    <View style={styles.container}>
      <TVStatusCard label="Tracks" value={stats.trackCount} />
      <TVStatusCard label="Albums" value={stats.albumCount} />
      <TVStatusCard label="Artists" value={stats.artistCount} />
      <TVStatusCard label="Users" value={stats.userCount} />

      {stats.storageTotal > 0 && (
        <TVStatusCard
          label="Storage"
          value={`${Math.round((stats.storageUsed / stats.storageTotal) * 100)}`}
          unit="%"
          variant={(stats.storageUsed / stats.storageTotal) > 0.9 ? 'warning' : 'default'}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: tvSpacing.gap_md,
  },
});
