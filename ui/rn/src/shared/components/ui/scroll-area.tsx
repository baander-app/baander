import React from 'react';
import { ScrollView, StyleSheet, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';

interface ScrollAreaProps {
  children: React.ReactNode;
  style?: ViewStyle;
  contentContainerStyle?: ViewStyle;
}

export function ScrollArea({ children, style, contentContainerStyle }: ScrollAreaProps) {
  return (
    <ScrollView
      style={[styles.base, style]}
      contentContainerStyle={contentContainerStyle}
      showsVerticalScrollIndicator={false}
    >
      {children}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  base: {
    flex: 1,
  },
});
