import React from 'react';
import { Pressable, Text, StyleSheet, type PressableProps } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';
import { styled } from '@/shared/styles/styled';

interface ButtonProps extends PressableProps {
  children: React.ReactNode;
  variant?: 'default' | 'secondary' | 'ghost' | 'destructive';
  size?: 'default' | 'sm' | 'lg';
}

const s = StyleSheet.create({
  base: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radii.md,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
  },
  defaultBg: { backgroundColor: colors.primary },
  secondaryBg: { backgroundColor: colors.secondary },
  ghostBg: { backgroundColor: 'transparent' },
  destructiveBg: { backgroundColor: colors.destructive },
  sm: { paddingHorizontal: spacing[3], paddingVertical: spacing[1] },
  lg: { paddingHorizontal: spacing[6], paddingVertical: spacing[3] },
  pressed: { opacity: 0.8 },
  disabled: { opacity: 0.5 },
  text: { fontSize: fontSizes.body, fontWeight: '500' },
  defaultText: { color: colors.foreground },
  secondaryText: { color: colors.foreground },
  ghostText: { color: colors.muted },
  destructiveText: { color: colors.foreground },
});

// Variant values come from the config, so TypeScript enforces that every
// `variant`/`size` a caller can pass has a matching style (R2 exhaustiveness).
const ButtonRoot = styled(Pressable, {
  base: s.base,
  variants: {
    variant: { default: s.defaultBg, secondary: s.secondaryBg, ghost: s.ghostBg, destructive: s.destructiveBg },
    size: { default: {}, sm: s.sm, lg: s.lg },
  },
  states: { pressed: s.pressed, disabled: s.disabled },
});

const ButtonLabel = styled(Text, {
  base: s.text,
  variants: { variant: { default: s.defaultText, secondary: s.secondaryText, ghost: s.ghostText, destructive: s.destructiveText } },
});

export function Button({ children, variant = 'default', size = 'default', disabled, ...rest }: ButtonProps) {
  return (
    <ButtonRoot variant={variant} size={size} disabled={disabled} {...rest}>
      {typeof children === 'string' ? <ButtonLabel variant={variant}>{children}</ButtonLabel> : children}
    </ButtonRoot>
  );
}
