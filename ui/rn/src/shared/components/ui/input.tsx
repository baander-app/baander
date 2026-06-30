import React from 'react';
import { TextInput, StyleSheet, type TextInputProps } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface InputProps extends TextInputProps {
  error?: boolean;
}

export function Input({ error, style, ...rest }: InputProps) {
  return (
    <TextInput
      style={[styles.input, error && styles.error, style]}
      placeholderTextColor={colors.muted}
      {...rest}
    />
  );
}

const styles = StyleSheet.create({
  input: {
    backgroundColor: colors.card,
    color: colors.foreground,
    borderRadius: radii.md,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[2],
    fontSize: fontSizes.body,
    borderWidth: 1,
    borderColor: colors.border,
  },
  error: {
    borderColor: colors.destructive,
  },
});
