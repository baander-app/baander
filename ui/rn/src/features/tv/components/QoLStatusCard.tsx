import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import type { QoLStatus, QoLStream } from '@/features/admin/hooks/useQoLStatus';

interface QoLStatusCardProps {
  status: QoLStatus | null;
  streams: QoLStream[];
}

export function QoLStatusCard({ status, streams }: QoLStatusCardProps) {
  if (!status) return null;

  const stateColor = status.state === 'active' ? tvColors.accent : tvColors.textMuted;

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Stream Governor</Text>

      <View style={styles.row}>
        <Text style={styles.label}>State:</Text>
        <Text style={[styles.value, { color: stateColor }]}>{status.state.toUpperCase()}</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Profile:</Text>
        <Text style={styles.value}>{status.profile}</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Budget Cap:</Text>
        <Text style={styles.value}>{(status.budget_cap * 100).toFixed(0)}%</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Samples:</Text>
        <Text style={styles.value}>{status.sample_count} / {status.model_ready ? '✓ Ready' : 'Learning…'}</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Active Streams:</Text>
        <Text style={styles.value}>{status.active_streams}</Text>
      </View>

      {streams.length > 0 && (
        <View style={styles.streamsSection}>
          <Text style={styles.sectionTitle}>Active Streams</Text>
          {streams.map((stream) => (
            <View key={stream.job_id} style={styles.streamRow}>
              <Text style={styles.streamTier}>{stream.quality_tier}</Text>
              <Text style={styles.streamCost}>{stream.predicted_cost.toFixed(1)}% CPU</Text>
            </View>
          ))}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: tvColors.card,
    borderRadius: 12,
    padding: tvSpacing.gap_lg,
  },
  title: {
    fontSize: tvFontSizes.lg,
    fontWeight: '700',
    color: tvColors.textPrimary,
    marginBottom: tvSpacing.gap_md,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: tvSpacing.gap_xs,
  },
  label: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
  value: {
    fontSize: tvFontSizes.body,
    fontWeight: '600',
    color: tvColors.textPrimary,
  },
  streamsSection: {
    marginTop: tvSpacing.gap_md,
    borderTopWidth: 1,
    borderTopColor: tvColors.border,
    paddingTop: tvSpacing.gap_md,
  },
  sectionTitle: {
    fontSize: tvFontSizes.body,
    fontWeight: '600',
    color: tvColors.textPrimary,
    marginBottom: tvSpacing.gap_sm,
  },
  streamRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: tvSpacing.gap_xs,
    paddingLeft: tvSpacing.gap_sm,
  },
  streamTier: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textSecondary,
  },
  streamCost: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textPrimary,
    fontWeight: '500',
  },
});
