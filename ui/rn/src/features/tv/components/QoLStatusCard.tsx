import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import { flattenStreams, sharedValue } from '@/features/admin/qol-report';
import type { QoLStatusReport, QoLStreamsReport } from '@/features/admin/qol-report';

interface QoLStatusCardProps {
  status: QoLStatusReport | null;
  streams: QoLStreamsReport | null;
}

/** The stream governor of every web server worker: shared state and profile, then one row per worker. */
export function QoLStatusCard({ status, streams }: QoLStatusCardProps) {
  if (!status) return null;

  const state = sharedValue(status.workers.map((worker) => worker.state));
  const profile = sharedValue(status.workers.map((worker) => worker.profile));
  const stateColor = state === 'active' ? tvColors.accent : tvColors.textMuted;
  const activeStreams = flattenStreams(streams);

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Stream Governor</Text>

      <View style={styles.row}>
        <Text style={styles.label}>State:</Text>
        <Text style={[styles.value, { color: stateColor }]}>{state === null ? '—' : state.toUpperCase()}</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Profile:</Text>
        <Text style={styles.value}>{profile ?? '—'}</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Active Streams:</Text>
        <Text style={styles.value}>{status.total.active_streams}</Text>
      </View>

      {status.workers.length > 0 && (
        <View style={styles.streamsSection}>
          <Text style={styles.sectionTitle}>Workers</Text>
          {status.workers.map((worker) => (
            <View key={worker.worker_id} style={styles.streamRow}>
              <Text style={styles.streamTier}>Worker {worker.worker_id}</Text>
              <Text style={styles.streamCost}>
                {worker.state.toUpperCase()} · {worker.profile} · {worker.sample_count} samples
                {worker.model_ready ? ' ✓ Ready' : ' Learning…'} · cap {(worker.budget_cap * 100).toFixed(0)}%
              </Text>
            </View>
          ))}
        </View>
      )}

      {activeStreams.length > 0 && (
        <View style={styles.streamsSection}>
          <Text style={styles.sectionTitle}>Active Streams</Text>
          {activeStreams.map((stream) => (
            <View key={`${stream.worker_id}-${stream.job_id}`} style={styles.streamRow}>
              <Text style={styles.streamTier}>Worker {stream.worker_id} · {stream.quality_tier}</Text>
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
