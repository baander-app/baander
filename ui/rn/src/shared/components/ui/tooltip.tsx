import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface TooltipProps {
  text: string;
  visible?: boolean;
}

export function Tooltip({ text, visible = true }: TooltipProps) {
  if (!visible) return null;

  return (
    <View style={styles.container}>
      <Text style={styles.text}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.foreground,
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[1],
    borderRadius: radii.md,
    alignSelf: 'flex-start',
  },
  text: {
    color: colors.background,
    fontSize: fontSizes.sm,
  },
});
