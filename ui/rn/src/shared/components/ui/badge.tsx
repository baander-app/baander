import React from 'react';
import { View, Text, StyleSheet, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface BadgeProps {
  children: string;
  variant?: 'default' | 'secondary' | 'destructive' | 'outline';
  style?: ViewStyle;
}

export function Badge({ children, variant = 'default', style }: BadgeProps) {
  return (
    <View style={[styles.base, styles[variant], style]}>
      <Text style={[styles.text, styles[`${variant}Text`]]}>{children}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  base: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[0.5],
    borderRadius: radii.full,
    alignSelf: 'flex-start',
  },
  default: {
    backgroundColor: colors.primary,
  },
  secondary: {
    backgroundColor: colors.secondary,
  },
  destructive: {
    backgroundColor: colors.destructive,
  },
  outline: {
    backgroundColor: 'transparent',
    borderWidth: 1,
    borderColor: colors.border,
  },
  text: {
    fontSize: fontSizes.label,
    fontWeight: '500',
  },
  defaultText: {
    color: colors.foreground,
  },
  secondaryText: {
    color: colors.foreground,
  },
  destructiveText: {
    color: colors.foreground,
  },
  outlineText: {
    color: colors.muted,
  },
});
