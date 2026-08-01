import React from 'react';
import { View, Text, StyleSheet, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';
import { styled } from '@/shared/styles/styled';

interface BadgeProps {
  children: string;
  variant?: 'default' | 'secondary' | 'destructive' | 'outline';
  style?: ViewStyle;
}

const s = StyleSheet.create({
  base: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[0.5],
    borderRadius: radii.full,
    alignSelf: 'flex-start',
  },
  defaultBg: { backgroundColor: colors.primary },
  secondaryBg: { backgroundColor: colors.secondary },
  destructiveBg: { backgroundColor: colors.destructive },
  outlineBg: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.border },
  text: { fontSize: fontSizes.label, fontWeight: '500' },
  defaultText: { color: colors.foreground },
  secondaryText: { color: colors.foreground },
  destructiveText: { color: colors.foreground },
  outlineText: { color: colors.muted },
});

const BadgeRoot = styled(View, {
  base: s.base,
  variants: { variant: { default: s.defaultBg, secondary: s.secondaryBg, destructive: s.destructiveBg, outline: s.outlineBg } },
});

const BadgeLabel = styled(Text, {
  base: s.text,
  variants: { variant: { default: s.defaultText, secondary: s.secondaryText, destructive: s.destructiveText, outline: s.outlineText } },
});

export function Badge({ children, variant = 'default', style }: BadgeProps) {
  return (
    <BadgeRoot variant={variant} style={style}>
      <BadgeLabel variant={variant}>{children}</BadgeLabel>
    </BadgeRoot>
  );
}
