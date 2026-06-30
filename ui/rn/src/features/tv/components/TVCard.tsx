/**
 * TVCard -- large pressable card for catalog content.
 *
 * Displays artwork (or placeholder), title, and subtitle.
 * Wrapped in TVFocusable for D-pad navigation.
 *
 * Size: 300x300px minimum for 10ft viewing.
 */

import React from 'react';
import { View, Image, Text, StyleSheet, ViewStyle } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvSizes, tvFontSizes, tvRadii } from '../theme/tv-tokens';

export interface TVCardProps {
  artworkUrl?: string | null;
  blurhash?: string | null;
  title: string;
  subtitle?: string | null;
  onPress?: () => void;
  onFocus?: () => void;
  onBlur?: () => void;
  style?: ViewStyle;
  isFocused?: boolean;
}

export function TVCard({
  artworkUrl,
  blurhash,
  title,
  subtitle,
  onPress,
  onFocus,
  onBlur,
  style,
  isFocused,
}: TVCardProps) {
  return (
    <TVFocusable
      onPress={onPress}
      onFocus={onFocus}
      onBlur={onBlur}
      isFocused={isFocused}
      style={[styles.container, style]}
    >
      {/* Artwork */}
      <View style={styles.artwork}>
        {artworkUrl ? (
          <Image source={{ uri: artworkUrl }} style={styles.image} resizeMode="cover" />
        ) : (
          <View style={styles.placeholder} />
        )}
      </View>

      {/* Title */}
      <Text style={styles.title} numberOfLines={1}>
        {title}
      </Text>

      {/* Subtitle */}
      {subtitle && (
        <Text style={styles.subtitle} numberOfLines={1}>
          {subtitle}
        </Text>
      )}
    </TVFocusable>
  );
}

const styles = StyleSheet.create({
  container: {
    width: tvSizes.cardWidth,
    height: tvSizes.cardHeight + 60, // Card height + text space
    gap: tvSizes.gap_sm,
  },
  artwork: {
    width: tvSizes.cardWidth,
    height: tvSizes.cardHeight,
    borderRadius: tvRadii.card_md,
    overflow: 'hidden',
    backgroundColor: tvColors.card,
  },
  image: {
    width: '100%',
    height: '100%',
  },
  placeholder: {
    width: '100%',
    height: '100%',
    backgroundColor: tvColors.card,
  },
  title: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    fontWeight: '500',
  },
  subtitle: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textSecondary,
  },
});
