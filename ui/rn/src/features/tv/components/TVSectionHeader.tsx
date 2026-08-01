/**
 * TVSectionHeader -- header for content sections.
 *
 * Displays section title and optional "View all" button.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export interface TVSectionHeaderProps {
  title: string;
  onViewAll?: () => void;
  viewAllText?: string;
}

export function TVSectionHeader({ title, onViewAll, viewAllText = 'View all' }: TVSectionHeaderProps) {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>{title}</Text>
      {onViewAll && (
        <TVFocusable onPress={onViewAll} style={styles.viewAllButton}>
          <Text style={styles.viewAllText}>{viewAllText}</Text>
        </TVFocusable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: tvSpacing.sectionPadding,
    paddingTop: tvSpacing.sectionPadding,
  },
  title: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  viewAllButton: {
    paddingHorizontal: tvSpacing.gap_md,
    paddingVertical: tvSpacing.gap_sm,
  },
  viewAllText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
});
