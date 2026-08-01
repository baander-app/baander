/**
 * TVAdminShell -- shell for all admin pages.
 *
 * Wraps admin pages with consistent layout:
 * - Header with page title and breadcrumb- Vertical menu (grouped by section)
 * - Content area (main content, vertical scroll)- Actions at bottom (save, cancel, etc.)
 */

import React from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSizes, tvSpacing } from '../theme/tv-tokens';

export interface TVAdminShellProps {
  title: string;
  children: React.ReactNode;
  actions?: React.ReactNode;
  breadcrumb?: string;
}

export function TVAdminShell({ title, children, actions, breadcrumb }: TVAdminShellProps) {
  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        {breadcrumb && <Text style={styles.breadcrumb}>{breadcrumb}</Text>}
        <Text style={styles.title}>{title}</Text>
      </View>

      {/* Content */}
      <ScrollView style={styles.content} showsVerticalScrollIndicator={false}>
        {children}
      </ScrollView>

      {/* Actions */}
      {actions && <View style={styles.actions}>{actions}</View>}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  header: {
    paddingHorizontal: tvSpacing.sectionPadding,
    paddingTop: tvSpacing.sectionPaddingLarge,
    paddingBottom: tvSpacing.sectionPadding,
    borderBottomWidth: 1,
    borderBottomColor: tvColors.border,
  },
  breadcrumb: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
    marginBottom: tvSpacing.gap_sm,
  },
  title: {
    fontSize: tvFontSizes['2xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  content: {
    flex: 1,
    padding: tvSpacing.sectionPadding,
  },
  actions: {
    padding: tvSpacing.sectionPadding,
    borderTopWidth: 1,
    borderTopColor: tvColors.border,
    flexDirection: 'row',
    gap: tvSpacing.gap_md,
    justifyContent: 'flex-end',
  },
});
