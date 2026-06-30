import React from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileAlbumDetailPage() {
  return (
    <ScrollView style={styles.container}>
      {/* Cover art header */}
      <View style={styles.header}>
        <View style={styles.coverArt} />
        <Text style={styles.albumTitle} numberOfLines={1}>Album Title</Text>
        <Text style={styles.albumArtist} numberOfLines={1}>Artist Name</Text>
      </View>

      {/* Track list */}
      <View style={styles.trackList}>
        {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
          <Pressable key={i} style={styles.trackRow}>
            <Text style={styles.trackNumber}>{i}</Text>
            <View style={styles.trackInfo}>
              <Text style={styles.trackTitle} numberOfLines={1}>Track {i}</Text>
              <Text style={styles.trackDuration}>3:{(20 + i).toString().padStart(2, '0')}</Text>
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
  header: {
    alignItems: 'center',
    paddingVertical: spacing[6],
    paddingHorizontal: spacing[4],
  },
  coverArt: {
    width: 240,
    height: 240,
    borderRadius: radii.xl,
    backgroundColor: colors.card,
    marginBottom: spacing[4],
  },
  albumTitle: {
    color: colors.foreground,
    fontSize: fontSizes.xl,
    fontWeight: '600',
    textAlign: 'center',
  },
  albumArtist: {
    color: colors.muted,
    fontSize: fontSizes.body,
    textAlign: 'center',
    marginTop: spacing[1],
  },
  trackList: {
    paddingBottom: 120,
  },
  trackRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[3],
  },
  trackNumber: {
    color: colors.muted,
    fontSize: fontSizes.body,
    width: 24,
    textAlign: 'right',
  },
  trackInfo: {
    flex: 1,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  trackTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    flex: 1,
  },
  trackDuration: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
