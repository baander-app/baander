/**
 * Media Type Selector -- segmented control for mobile.
 *
 * Compact horizontal chips: Music | Movies | TV | etc.
 */

import React from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet } from 'react-native';
import { useMediaModeStore, type MediaType } from '@/features/desktop/stores/media-mode-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

const MEDIA_TYPES: MediaType[] = ['music', 'movies', 'tv'];

export function MediaTypeSelector() {
  const { activeMedia, setActiveMedia } = useMediaModeStore();

  return (
    <View style={styles.container}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false}>
        {MEDIA_TYPES.map((type) => (
          <Pressable
            key={type}
            style={[styles.chip, activeMedia === type && styles.chipActive]}
            onPress={() => setActiveMedia(type)}
          >
            <Text style={[styles.chipText, activeMedia === type && styles.chipTextActive]}>
              {type.charAt(0).toUpperCase() + type.slice(1)}
            </Text>
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[3],
  },
  chip: {
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[1.5],
    borderRadius: radii.full,
    backgroundColor: colors.card,
    marginRight: spacing[2],
  },
  chipActive: {
    backgroundColor: colors.primary,
  },
  chipText: {
    color: colors.muted,
    fontSize: fontSizes.sm,
    fontWeight: '500',
  },
  chipTextActive: {
    color: colors.foreground,
  },
});
