/**
 * TV Badge -- small badge component for TV interfaces.
 *
 * Displays status indicators, counts, or labels.
 * Large touch target for D-pad navigation.
 */

import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { tvColors, tvFontSizes, tvRadii } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVBadgeProps {
  children: string | number;
  variant?: 'default' | 'primary' | 'secondary' | 'success' | 'warning' | 'error';
  size?: 'sm' | 'md' | 'lg';
  style?: ViewStyle;
}

export function TVBadge({ children, variant = 'default', size = 'md', style }: TVBadgeProps) {
  return (
    <View style={[styles.container, styles[variant], styles[size], style]}>
      <Text style={[styles.text, styles[`${size}Text`]]}>{String(children)}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingHorizontal: 12,
    paddingVertical: 4,
    borderRadius: tvRadii.sm,
    alignSelf: 'flex-start',
  },
  text: {
    fontWeight: '600',
  },
  // Variants
  default: {
    backgroundColor: tvColors.card,
  },
  primary: {
    backgroundColor: tvColors.primary,
  },
  secondary: {
    backgroundColor: tvColors.secondary,
  },
  success: {
    backgroundColor: '#22c55e',
  },
  warning: {
    backgroundColor: '#f59e0b',
  },
  error: {
    backgroundColor: tvColors.destructive,
  },
  // Sizes
  sm: {
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  md: {
    paddingHorizontal: 12,
    paddingVertical: 4,
  },
  lg: {
    paddingHorizontal: 16,
    paddingVertical: 6,
  },
  // Text sizes
  smText: {
    fontSize: tvFontSizes.xs,
  },
  mdText: {
    fontSize: tvFontSizes.label,
  },
  lgText: {
    fontSize: tvFontSizes.sm,
  },
});
