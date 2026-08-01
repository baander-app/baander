import React from 'react';
import { TextInput, StyleSheet, type TextInputProps } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';
import { styled } from '@/shared/styles/styled';

interface InputProps extends TextInputProps {
  error?: boolean;
}

const s = StyleSheet.create({
  base: {
    backgroundColor: colors.card,
    color: colors.foreground,
    borderRadius: radii.md,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[2],
    fontSize: fontSizes.body,
    borderWidth: 1,
    borderColor: colors.border,
  },
  errorBorder: { borderColor: colors.destructive },
});

// `error` is mapped to a `state` variant so the style config owns which values
// exist; callers cannot pass a state with no matching style (R2 exhaustiveness).
const InputField = styled(TextInput, {
  base: s.base,
  variants: { state: { default: {}, error: s.errorBorder } },
});

export function Input({ error, ...rest }: InputProps) {
  return <InputField state={error ? 'error' : 'default'} placeholderTextColor={colors.muted} {...rest} />;
}
