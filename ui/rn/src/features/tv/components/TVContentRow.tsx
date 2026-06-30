/**
 * TVContentRow -- horizontally scrollable row of content.
 *
 * Uses ScrollView horizontal for left/right D-pad navigation.
 * TVFocusGuideView trapFocus prevents focus escaping the row.
 *
 * Use for album rows, artist rows, genre chips, etc.
 */

import React from 'react';
import { View, ScrollView, StyleSheet, ViewStyle } from 'react-native';
import { TVFocusGuideView } from 'react-native-tvos';
import { TVFocusable } from './TVFocusable';
import { TVSectionHeader } from './TVSectionHeader';
import { tvColors, tvSpacing } from '../theme/tv-tokens';

export interface TVContentRowProps {
  title: string;
  children: React.ReactNode;
  onViewAll?: () => void;
  style?: ViewStyle;
  trapFocus?: boolean;
}

export function TVContentRow({
  title,
  children,
  onViewAll,
  style,
  trapFocus = true,
}: TVContentRowProps) {
  return (
    <TVFocusGuideView trapFocusLeft={trapFocus} trapFocusRight={trapFocus} style={styles.container}>
      {/* Section header */}
      <TVSectionHeader title={title} onViewAll={onViewAll} />

      {/* Horizontal scroll */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
        style={styles.scroll}
      >
        {children}
      </ScrollView>
    </TVFocusGuideView>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: tvSpacing.rowGap,
  },
  scroll: {
    flexGrow: 0,
  },
  scrollContent: {
    paddingHorizontal: tvSpacing.sectionPadding,
    gap: tvSpacing.rowItemGap,
  },
});
