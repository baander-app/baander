/**
 * TV Data Row -- label-value pair row for TV forms and detail views.
 *
 * Displays label and value horizontally with large text.
 */

import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { tvColors, tvFontSizes, tvSizes, tvSpacing } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVDataRowProps {
  label: string;
  value: string | number | null | undefined;
  style?: ViewStyle;
}

export function TVDataRow({ label, value, style }: TVDataRowProps) {
  return (
    <View style={[styles.container, style]}>
      <Text style={styles.label}>{label}</Text>
      <Text style={styles.value}>{value ?? '—'}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: tvSpacing.gap_sm,
    paddingHorizontal: tvSpacing.sectionPadding,
    minHeight: tvSizes.touchTarget,
  },
  label: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
    flex: 1,
  },
  value: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    fontWeight: '500',
    textAlign: 'right',
  },
});
