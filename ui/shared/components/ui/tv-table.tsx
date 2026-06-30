/**
 * TV Table -- vertical list table for TV interfaces.
 *
 * Renders rows as vertical list (no horizontal scrolling).
 * Optimized for D-pad navigation.
 */

import React from 'react';
import { View, StyleSheet, ViewStyle } from 'react-native';
import { tvColors, tvSpacing } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVTableProps {
  children: React.ReactNode;
  style?: ViewStyle;
}

export function TVTable({ children, style }: TVTableProps) {
  return <View style={[styles.container, style]}>{children}</View>;
}

const styles = StyleSheet.create({
  container: {
    gap: tvSpacing.gap_sm,
  },
});
