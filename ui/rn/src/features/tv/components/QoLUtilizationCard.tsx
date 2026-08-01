import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import type { QoLUtilization } from '@/features/admin/hooks/useQoLUtilization';

interface QoLUtilizationCardProps {
  data: QoLUtilization | null;
}

export function QoLUtilizationCard({ data }: QoLUtilizationCardProps) {
  if (!data) return null;

  const budgetPercent = data.budget_cap * 100;
  const barColor = data.state === 'active' ? tvColors.accent : tvColors.textMuted;

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Budget Utilization</Text>

      <View style={styles.budgetBarBackground}>
        <View
          style={[
            styles.budgetBarFill,
            { width: `${budgetPercent}%`, backgroundColor: barColor },
          ]}
        />
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Cap:</Text>
        <Text style={styles.value}>{budgetPercent.toFixed(0)}%</Text>
      </View>

      <View style={styles.row}>
        <Text style={styles.label}>Active Streams:</Text>
        <Text style={styles.value}>{data.active_streams}</Text>
      </View>

      <Text style={styles.hint}>
        {data.state === 'learning'
          ? 'Learning from transcode patterns…'
          : 'Enforcing CPU budget'}
      </Text>
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
