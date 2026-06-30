/**
 * TV Select -- large select dropdown for TV interfaces.
 *
 * Uses TVFocusable for D-pad navigation.
 * Shows options as modal list when focused.
 */

import React, { useState } from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { TVFocusable } from '../../../rn/src/features/tv/components/TVFocusable';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../../../rn/src/features/tv/theme/tv-tokens';

export interface TVSelectOption {
  label: string;
  value: string;
}

export interface TVSelectProps {
  label: string;
  options: TVSelectOption[];
  value: string;
  onChange: (value: string) => void;
  style?: ViewStyle;
}

export function TVSelect({ label, options, value, onChange, style }: TVSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const selectedOption = options.find((opt) => opt.value === value);

  return (
    <View style={[styles.container, style]}>
      <Text style={styles.label}>{label}</Text>

      {isOpen ? (
        <View style={styles.options}>
          {options.map((option) => (
            <TVFocusable
              key={option.value}
              onPress={() => {
                onChange(option.value);
                setIsOpen(false);
              }}
              style={styles.option}
              isFocused={option.value === value}
            >
              <Text style={styles.optionText}>{option.label}</Text>
            </TVFocusable>
          ))}
        </View>
      ) : (
        <TVFocusable
          onPress={() => setIsOpen(true)}
          style={styles.trigger}
          contentStyle={styles.triggerContent}
        >
          <Text style={styles.triggerText}>
            {selectedOption?.label || 'Select...'}
          </Text>
        </TVFocusable>
      )}
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
  trigger: {
    height: tvSizes.inputHeight,
  },
  triggerContent: {
    flex: 1,
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_sm,
    borderWidth: 1,
    borderColor: tvColors.border,
    paddingHorizontal: tvSpacing.gap_md,
    justifyContent: 'center',
  },
  triggerText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
  options: {
    gap: tvSpacing.gap_sm,
  },
  option: {
    height: tvSizes.touchTarget,
    paddingHorizontal: tvSpacing.gap_md,
  },
  optionText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
});
