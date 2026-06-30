/**
 * Mobile Home Page -- browse featured/recent content.
 *
 * Layout: MediaTypeSelector | Featured Albums | Recently Played
 */

import React from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { MediaTypeSelector } from '../components/MediaTypeSelector';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileHomePage() {
  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <MediaTypeSelector />

      {/* Featured Albums */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Featured</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.row}>
          {[1, 2, 3, 4, 5].map((i) => (
            <View key={i} style={styles.albumCard}>
              <View style={styles.albumArt} />
              <Text style={styles.albumTitle} numberOfLines={1}>Album {i}</Text>
              <Text style={styles.albumArtist} numberOfLines={1}>Artist</Text>
            </View>
          ))}
        </ScrollView>
      </View>

      {/* Recently Played */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Recently Played</Text>
        {[1, 2, 3, 4, 5].map((i) => (
          <Pressable key={i} style={styles.trackRow}>
            <View style={styles.trackArtSmall} />
            <View style={styles.trackInfo}>
              <Text style={styles.trackTitle} numberOfLines={1}>Track {i}</Text>
              <Text style={styles.trackArtist} numberOfLines={1}>Artist</Text>
            </View>
          </Pressable>
        ))}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    paddingBottom: 120, // space for mini-player + tab bar
  },
  section: {
    marginTop: spacing[4],
  },
  sectionTitle: {
    color: colors.foreground,
    fontSize: fontSizes.lg,
    fontWeight: '600',
    paddingHorizontal: spacing[4],
    marginBottom: spacing[2],
  },
  row: {
    paddingHorizontal: spacing[3],
  },
  albumCard: {
    width: 140,
    marginRight: spacing[3],
  },
  albumArt: {
    width: 140,
    height: 140,
    borderRadius: radii.lg,
    backgroundColor: colors.card,
    marginBottom: spacing[2],
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  albumArtist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  trackRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    gap: spacing[3],
  },
  trackArtSmall: {
    width: 40,
    height: 40,
    borderRadius: radii.md,
    backgroundColor: colors.card,
  },
  trackInfo: {
    flex: 1,
    minWidth: 0,
  },
  trackTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  trackArtist: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
