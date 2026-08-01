/**
 * TV Status Card -- displays single stat with large number and label.
 *
 * Used in admin dashboard for server metrics.
 */

import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing, tvRadii } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVStatusCardProps {
  label: string;
  value: string | number;
  unit?: string;
  variant?: 'default' | 'success' | 'warning' | 'error';
  style?: ViewStyle;
}

export function TVStatusCard({ label, value, unit, variant = 'default', style }: TVStatusCardProps) {
  return (
    <View style={[styles.container, styles[variant], style]}>
      <Text style={styles.value}>{String(value)}</Text>
      {unit && <Text style={styles.unit}>{unit}</Text>}
      <Text style={styles.label}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_md,
    padding: tvSpacing.gap_lg,
    gap: tvSpacing.gap_sm,
    borderWidth: 2,
    borderColor: 'transparent',
  },
  value: {
    fontSize: tvFontSizes['4xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  unit: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textSecondary,
    marginLeft: tvSpacing.gap_sm,
  },
  label: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
  },
  // Variants
  default: {},
  success: {
    borderColor: '#22c55e',
  },
  warning: {
    borderColor: '#f59e0b',
  },
  error: {
    borderColor: tvColors.destructive,
  },
});
