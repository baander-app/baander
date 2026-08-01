import React from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileArtistDetailPage() {
  return (
    <ScrollView style={styles.container}>
      {/* Hero image */}
      <View style={styles.hero}>
        <View style={styles.heroImage} />
        <Text style={styles.artistName}>Artist Name</Text>
      </View>

      {/* Albums section */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Albums</Text>
        {[1, 2, 3, 4].map((i) => (
          <View key={i} style={styles.albumRow}>
            <View style={styles.albumArtSmall} />
            <View style={styles.albumInfo}>
              <Text style={styles.albumTitle} numberOfLines={1}>Album {i}</Text>
              <Text style={styles.albumMeta}>2024 · 12 tracks</Text>
            </View>
          </View>
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
  hero: {
    alignItems: 'center',
    paddingVertical: spacing[8],
    paddingHorizontal: spacing[4],
  },
  heroImage: {
    width: 160,
    height: 160,
    borderRadius: 80,
    backgroundColor: colors.card,
    marginBottom: spacing[4],
  },
  artistName: {
    color: colors.foreground,
    fontSize: fontSizes.xl,
    fontWeight: '600',
  },
  section: {
    marginTop: spacing[4],
    paddingBottom: 120,
  },
  sectionTitle: {
    color: colors.foreground,
    fontSize: fontSizes.lg,
    fontWeight: '600',
    paddingHorizontal: spacing[4],
    marginBottom: spacing[2],
  },
  albumRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    gap: spacing[3],
  },
  albumArtSmall: {
    width: 48,
    height: 48,
    borderRadius: radii.md,
    backgroundColor: colors.card,
  },
  albumInfo: {
    flex: 1,
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  albumMeta: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
