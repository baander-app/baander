import React from 'react';
import { View, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { radii } from '@/shared/theme/tokens';

interface SliderProps {
  value: number;
  minimumValue?: number;
  maximumValue?: number;
  onValueChange?: (value: number) => void;
  style?: any;
}

export function Slider({
  value,
  minimumValue = 0,
  maximumValue = 100,
  onValueChange,
  style,
}: SliderProps) {
  const pct = ((value - minimumValue) / (maximumValue - minimumValue)) * 100;

  return (
    <View style={[styles.track, style]}>
      <View style={[styles.fill, { width: `${pct}%` as any }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.border,
    overflow: 'hidden',
  },
  fill: {
    height: '100%',
    backgroundColor: colors.foreground,
    borderRadius: 2,
  },
});
