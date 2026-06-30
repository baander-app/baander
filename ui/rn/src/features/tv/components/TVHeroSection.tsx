/**
 * TVHeroSection -- featured content section with extra-large artwork.
 *
 * Displays hero content with large artwork (600x600px) and action buttons.
 * Wrapped in TVFocusGuideView with autoFocus to receive focus on first load.
 *
 * Use for featured albums, new releases, promotions.
 */

import React from 'react';
import { View, Image, Text, StyleSheet, ScrollView, ViewStyle } from 'react-native';
import { TVFocusGuideView } from 'react-native-tvos';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvSizes, tvFontSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';

export interface TVHeroSectionProps {
  artworkUrl?: string | null;
  title: string;
  subtitle?: string | null;
  description?: string | null;
  primaryAction?: { label: string; onPress: () => void };
  secondaryAction?: { label: string; onPress: () => void };
  style?: ViewStyle;
  autoFocus?: boolean;
}

export function TVHeroSection({
  artworkUrl,
  title,
  subtitle,
  description,
  primaryAction,
  secondaryAction,
  style,
  autoFocus = true,
}: TVHeroSectionProps) {
  return (
    <TVFocusGuideView autoFocus={autoFocus} style={[styles.container, style]}>
      <ScrollView
        horizontal
        contentContainerStyle={styles.scrollContent}
        showsHorizontalScrollIndicator={false}
      >
        {/* Artwork */}
        <View style={styles.artworkContainer}>
          {artworkUrl ? (
            <Image source={{ uri: artworkUrl }} style={styles.artwork} resizeMode="cover" />
          ) : (
            <View style={styles.artworkPlaceholder} />
          )}
        </View>

        {/* Content */}
        <View style={styles.content}>
          <Text style={styles.title} numberOfLines={2}>
            {title}
          </Text>
          {subtitle && (
            <Text style={styles.subtitle} numberOfLines={1}>
              {subtitle}
            </Text>
          )}
          {description && (
            <Text style={styles.description} numberOfLines={3}>
              {description}
            </Text>
          )}

          {/* Actions */}
          <View style={styles.actions}>
            {primaryAction && (
              <TVFocusable onPress={primaryAction.onPress} style={styles.primaryButton}>
                <Text style={styles.primaryButtonText}>{primaryAction.label}</Text>
              </TVFocusable>
            )}
            {secondaryAction && (
              <TVFocusable onPress={secondaryAction.onPress} style={styles.secondaryButton}>
                <Text style={styles.secondaryButtonText}>{secondaryAction.label}</Text>
              </TVFocusable>
            )}
          </View>
        </View>
      </ScrollView>
    </TVFocusGuideView>
  );
}

const styles = StyleSheet.create({
  container: {
    height: tvSizes.cardHeroHeight + 80,
    marginVertical: tvSpacing.rowGap,
  },
  scrollContent: {
    paddingHorizontal: tvSpacing.sectionPadding,
    gap: tvSpacing.gap_lg,
  },
  artworkContainer: {
    width: tvSizes.cardHeroWidth,
    height: tvSizes.cardHeroHeight,
    borderRadius: tvRadii.card_lg,
    overflow: 'hidden',
  },
  artwork: {
    width: '100%',
    height: '100%',
  },
  artworkPlaceholder: {
    width: '100%',
    height: '100%',
    backgroundColor: tvColors.card,
  },
  content: {
    flex: 1,
    maxWidth: 600,
    justifyContent: 'center',
    gap: tvSpacing.gap_sm,
  },
  title: {
    fontSize: tvFontSizes['3xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  subtitle: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textSecondary,
  },
  description: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
  },
  actions: {
    flexDirection: 'row',
    gap: tvSpacing.gap_md,
    marginTop: tvSpacing.gap_md,
  },
  primaryButton: {
    paddingHorizontal: tvSpacing.gap_xl,
    paddingVertical: tvSpacing.gap_md,
    backgroundColor: tvColors.primary,
    borderRadius: tvRadii.card_md,
  },
  primaryButtonText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  secondaryButton: {
    paddingHorizontal: tvSpacing.gap_xl,
    paddingVertical: tvSpacing.gap_md,
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_md,
  },
  secondaryButtonText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
});
