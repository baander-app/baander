/**
 * TVNavigationBar -- top navigation bar for TV screens.
 *
 * Displays:
 * - Media type selector (Music only for now — extendable to Movies, TV Shows, etc.)
 * - Action buttons (Search, Settings, Admin)
 *
 * All buttons are TVFocusable for D-pad navigation.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvSizes, tvFontSizes, tvRadii } from '../theme/tv-tokens';

export interface TVNavigationBarProps {
  onSearchPress?: () => void;
  onSettingsPress?: () => void;
  onAdminPress?: () => void;
}

export function TVNavigationBar({ onSearchPress, onSettingsPress, onAdminPress }: TVNavigationBarProps) {
  return (
    <View style={styles.container}>
      {/* Media type selector */}
      <View style={styles.section}>
        <TVFocusable style={styles.mediaTypeButton}>
          <Text style={styles.mediaTypeText}>Music</Text>
        </TVFocusable>
      </View>

      {/* Action buttons */}
      <View style={styles.actions}>
        {onSearchPress && (
          <TVFocusable onPress={onSearchPress} style={styles.actionButton}>
            <Text style={styles.actionText}>Search</Text>
          </TVFocusable>
        )}
        {onSettingsPress && (
          <TVFocusable onPress={onSettingsPress} style={styles.actionButton}>
            <Text style={styles.actionText}>Settings</Text>
          </TVFocusable>
        )}
        {onAdminPress && (
          <TVFocusable onPress={onAdminPress} style={styles.actionButton}>
            <Text style={styles.actionText}>Admin</Text>
          </TVFocusable>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    height: tvSizes.navBarHeight,
    paddingHorizontal: tvSizes.sectionPadding,
    backgroundColor: tvColors.card,
    borderBottomWidth: 1,
    borderBottomColor: tvColors.border,
  },
  section: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: tvSizes.gap_sm,
  },
  mediaTypeButton: {
    paddingHorizontal: tvSizes.gap_md,
    paddingVertical: tvSizes.gap_sm,
    borderRadius: tvRadii.card_md,
  },
  mediaTypeText: {
    fontSize: tvFontSizes.lg,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  actions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: tvSizes.gap_sm,
  },
  actionButton: {
    paddingHorizontal: tvSizes.gap_md,
    paddingVertical: tvSizes.gap_sm,
    borderRadius: tvRadii.card_md,
  },
  actionText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
});
