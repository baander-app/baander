import React from 'react';
import { Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

interface ToggleProps {
  pressed: boolean;
  onPressedChange?: (pressed: boolean) => void;
  children: React.ReactNode;
  style?: any;
}

export function Toggle({ pressed, onPressedChange, children, style }: ToggleProps) {
  return (
    <Pressable
      style={[styles.base, pressed && styles.pressed, style]}
      onPress={() => onPressedChange?.(!pressed)}
    >
      {children}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[2],
    borderRadius: radii.md,
    backgroundColor: 'transparent',
  },
  pressed: {
    backgroundColor: colors.secondary,
  },
});
