import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import { sharedValue } from '@/features/admin/qol-report';
import type { QoLStatusReport } from '@/features/admin/qol-report';

interface QoLUtilizationCardProps {
  data: QoLStatusReport | null;
}

const HINTS = {
  learning: 'Learning from transcode patterns…',
  active: 'Enforcing CPU budget',
  mixed: 'Some workers are still learning…',
} as const;

/** The CPU budget cap of every web server worker and the total of active streams. */
export function QoLUtilizationCard({ data }: QoLUtilizationCardProps) {
  if (!data) return null;

  const state = sharedValue(data.workers.map((worker) => worker.state));

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Budget Utilization</Text>

      {data.workers.map((worker) => {
        const budgetPercent = worker.budget_cap * 100;
        const barColor = worker.state === 'active' ? tvColors.accent : tvColors.textMuted;

        return (
          <View key={worker.worker_id}>
            <View style={styles.row}>
              <Text style={styles.label}>Worker {worker.worker_id} cap:</Text>
              <Text style={styles.value}>{budgetPercent.toFixed(0)}%</Text>
            </View>
            <View style={styles.budgetBarBackground}>
              <View
                style={[
                  styles.budgetBarFill,
                  { width: `${budgetPercent}%`, backgroundColor: barColor },
                ]}
              />
            </View>
          </View>
        );
      })}

      <View style={styles.row}>
        <Text style={styles.label}>Active Streams:</Text>
        <Text style={styles.value}>{data.total.active_streams}</Text>
      </View>

      {state !== null && <Text style={styles.hint}>{HINTS[state]}</Text>}
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
  budgetBarBackground: {
    height: 8,
    backgroundColor: tvColors.border,
    borderRadius: 4,
    marginBottom: tvSpacing.gap_md,
    overflow: 'hidden',
  },
  budgetBarFill: {
    height: '100%',
    borderRadius: 4,
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
  hint: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textMuted,
    marginTop: tvSpacing.gap_sm,
  },
});
