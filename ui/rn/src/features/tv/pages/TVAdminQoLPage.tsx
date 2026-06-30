import React from 'react';
import { ScrollView, Text, StyleSheet } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';
import { TVFocusable } from '../components/TVFocusable';
import { QoLStatusCard } from '../components/QoLStatusCard';
import { QoLUtilizationCard } from '../components/QoLUtilizationCard';
import { useQoLStatus } from '@/features/admin/hooks/useQoLStatus';
import { useQoLUtilization } from '@/features/admin/hooks/useQoLUtilization';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export function TVAdminQoLPage() {
  const { status, streams, isLoading, error, refetch } = useQoLStatus();
  const utilization = useQoLUtilization();

  return (
    <TVAdminShell
      title="Stream Governor"
      breadcrumb="Admin / QoL"
      actions={
        <TVFocusable onPress={refetch} style={styles.refreshButton}>
          <Text style={styles.refreshText}>Refresh</Text>
        </TVFocusable>
      }
    >
      {isLoading ? (
        <Text style={styles.loading}>Loading…</Text>
      ) : error ? (
        <Text style={styles.error}>Error: {error.message}</Text>
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <QoLUtilizationCard data={utilization.data} />
          <QoLStatusCard status={status} streams={streams} />
        </ScrollView>
      )}
    </TVAdminShell>
  );
}

const styles = StyleSheet.create({
  content: {
    padding: tvSpacing.gap_lg,
    gap: tvSpacing.gap_lg,
  },
  loading: {
    fontSize: tvFontSizes.lg,
    color: tvColors.textSecondary,
    textAlign: 'center',
    marginTop: tvSpacing.gap_xl,
  },
  error: {
    fontSize: tvFontSizes.lg,
    color: tvColors.destructive,
    textAlign: 'center',
    marginTop: tvSpacing.gap_xl,
  },
  refreshButton: {
    paddingHorizontal: tvSpacing.gap_md,
    paddingVertical: tvSpacing.gap_sm,
    backgroundColor: tvColors.primary,
    borderRadius: 6,
  },
  refreshText: {
    fontSize: tvFontSizes.sm,
    color: tvColors.card,
    fontWeight: '600',
  },
});
