import React from 'react';
import { View, StyleSheet, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { radii } from '@/shared/theme/tokens';

interface SkeletonProps {
  style?: ViewStyle;
}

export function Skeleton({ style }: SkeletonProps) {
  return <View style={[styles.skeleton, style]} />;
}

const styles = StyleSheet.create({
  skeleton: {
    backgroundColor: colors.border,
    borderRadius: radii.md,
  },
});
