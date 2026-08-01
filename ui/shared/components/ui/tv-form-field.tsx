/**
 * TV Form Field -- large touch target form input for TV.
 *
 * Wraps TextInput with TV-optimized styling.
 */

import React from 'react';
import { View, Text, TextInput, StyleSheet, ViewStyle, TextInputProps } from 'react-native';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVFormFieldProps extends Omit<TextInputProps, 'style'> {
  label: string;
  error?: string;
  containerStyle?: ViewStyle;
}

export function TVFormField({ label, error, containerStyle, ...textInputProps }: TVFormFieldProps) {
  return (
    <View style={[styles.container, containerStyle]}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        style={[styles.input, error && styles.inputError]}
        placeholderTextColor={tvColors.textMuted}
        {...textInputProps}
      />
      {error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: tvSpacing.gap_sm,
  },
  label: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
  input: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_sm,
    borderWidth: 1,
    borderColor: tvColors.border,
    paddingHorizontal: tvSpacing.gap_md,
    paddingVertical: tvSpacing.gap_sm,
    height: tvSizes.inputHeight,
  },
  inputError: {
    borderColor: tvColors.destructive,
  },
  errorText: {
    fontSize: tvFontSizes.sm,
    color: tvColors.destructive,
  },
});
