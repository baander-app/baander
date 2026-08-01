import React from 'react';
import { View, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

interface SwitchProps {
  value: boolean;
  onValueChange?: (value: boolean) => void;
  disabled?: boolean;
}

export function Switch({ value, onValueChange, disabled }: SwitchProps) {
  return (
    <Pressable
      style={[styles.track, value && styles.trackActive, disabled && styles.disabled]}
      onPress={() => !disabled && onValueChange?.(!value)}
    >
      <View style={[styles.thumb, value && styles.thumbActive]} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  track: {
    width: 44,
    height: 24,
    borderRadius: 12,
    backgroundColor: colors.border,
    padding: 2,
    justifyContent: 'center',
  },
  trackActive: {
    backgroundColor: colors.primary,
  },
  thumb: {
    width: 20,
    height: 20,
    borderRadius: 10,
    backgroundColor: colors.foreground,
  },
  thumbActive: {
    alignSelf: 'flex-end',
  },
  disabled: {
    opacity: 0.5,
  },
});
