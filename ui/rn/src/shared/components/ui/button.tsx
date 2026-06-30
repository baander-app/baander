import React from 'react';
import { Pressable, Text, StyleSheet, type PressableProps, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface ButtonProps extends PressableProps {
  children: React.ReactNode;
  variant?: 'default' | 'secondary' | 'ghost' | 'destructive';
  size?: 'default' | 'sm' | 'lg';
  style?: ViewStyle;
}

export function Button({
  children,
  variant = 'default',
  size = 'default',
  style,
  disabled,
  ...rest
}: ButtonProps) {
  return (
    <Pressable
      style={({ pressed }) => [
        styles.base,
        styles[variant],
        styles[size],
        pressed && styles.pressed,
        disabled && styles.disabled,
        style,
      ]}
      disabled={disabled}
      {...rest}
    >
      {typeof children === 'string' ? (
        <Text style={[styles.text, styles[`${variant}Text`]]}>{children}</Text>
      ) : (
        children
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radii.md,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
  },
  default: {
    backgroundColor: colors.primary,
  },
  secondary: {
    backgroundColor: colors.secondary,
  },
  ghost: {
    backgroundColor: 'transparent',
  },
  destructive: {
    backgroundColor: colors.destructive,
  },
  sm: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1],
  },
  lg: {
    paddingHorizontal: spacing[6],
    paddingVertical: spacing[3],
  },
  pressed: {
    opacity: 0.8,
  },
  disabled: {
    opacity: 0.5,
  },
  text: {
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  defaultText: {
    color: colors.foreground,
  },
  secondaryText: {
    color: colors.foreground,
  },
  ghostText: {
    color: colors.muted,
  },
  destructiveText: {
    color: colors.foreground,
  },
});
