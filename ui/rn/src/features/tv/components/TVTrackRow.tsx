/**
 * TVTrackRow -- horizontal list item for track listings.
 *
 * Displays track number, title, duration, and focus indicator.
 * Used in album/artist detail pages for track lists.
 */

import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvSizes, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export interface TVTrackRowProps {
  trackNumber: number | null;
  title: string;
  artistName?: string | null;
  duration?: number | null;
  isFocused?: boolean;
  onPress?: () => void;
  onFocus?: () => void;
  onBlur?: () => void;
  style?: ViewStyle;
}

/**
 * Format duration in seconds to MM:SS.
 */
function formatDuration(seconds: number | null): string {
  if (!seconds) return '--:--';
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

export function TVTrackRow({
  trackNumber,
  title,
  artistName,
  duration,
  isFocused,
  onPress,
  onFocus,
  onBlur,
  style,
}: TVTrackRowProps) {
  return (
    <TVFocusable
      onPress={onPress}
      onFocus={onFocus}
      onBlur={onBlur}
      isFocused={isFocused}
      style={[styles.container, style]}
      contentStyle={styles.content}
    >
      {/* Track number */}
      <Text style={styles.trackNumber}>{trackNumber ?? '-'}</Text>

      {/* Track info */}
      <View style={styles.trackInfo}>
        <Text style={styles.title} numberOfLines={1}>
          {title}
        </Text>
        {artistName && (
          <Text style={styles.artistName} numberOfLines={1}>
            {artistName}
          </Text>
        )}
      </View>

      {/* Duration */}
      <Text style={styles.duration}>{formatDuration(duration)}</Text>
    </TVFocusable>
  );
}

const styles = StyleSheet.create({
  container: {
    height: tvSizes.touchTarget,
    paddingHorizontal: tvSpacing.sectionPadding,
    paddingVertical: tvSpacing.gap_sm,
  },
  content: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: tvSpacing.gap_md,
  },
  trackNumber: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
    width: 32,
    textAlign: 'center',
  },
  trackInfo: {
    flex: 1,
    gap: 2,
  },
  title: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
  artistName: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textSecondary,
  },
  duration: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
    width: 48,
    textAlign: 'right',
  },
});
