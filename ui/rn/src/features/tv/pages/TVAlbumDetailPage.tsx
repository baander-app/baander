/**
 * TVAlbumDetailPage -- album detail page with track listing.
 *
 * Shows large artwork at top, TVTrackRow list below for tracks.
 */

import React from 'react';
import { View, ScrollView, StyleSheet } from 'react-native';
import { useRoute, useNavigation, RouteProp } from '@react-navigation/native';
import { Image, Text } from 'react-native';
import { TVTrackRow } from '../components/TVTrackRow';
import { useTracks } from '@/features/catalog/hooks/useTracks';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';
import type { TVRouteParams } from '../navigation/TVRoutes';

type AlbumDetailRoute = RouteProp<TVRouteParams, 'TVAlbumDetail'>;

export function TVAlbumDetailPage() {
  const route = useRoute<AlbumDetailRoute>();
  const navigation = useNavigation();
  const { publicId } = route.params;

  const { data: tracks, isLoading } = useTracks(publicId);

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Artwork */}
      <View style={styles.artworkContainer}>
        <Image
          source={{ uri: 'https://via.placeholder.com/400' }}
          style={styles.artwork}
          resizeMode="cover"
        />
        <View style={styles.info}>
          <Text style={styles.title}>Album Title</Text>
          <Text style={styles.artist}>Artist Name</Text>
        </View>
      </View>

      {/* Track list */}
      {isLoading ? (
        <Text style={styles.loadingText}>Loading tracks...</Text>
      ) : (
        tracks.map((track, index) => (
          <TVTrackRow
            key={track.uuid}
            trackNumber={track.trackNumber ?? index + 1}
            title={track.title}
            artistName={track.artistName}
            duration={track.duration}
            onPress={() => {}}
          />
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  artworkContainer: {
    padding: tvSpacing.sectionPaddingLarge,
    gap: tvSpacing.gap_md,
  },
  artwork: {
    width: tvSizes.cardHeroWidth,
    height: tvSizes.cardHeroHeight,
    borderRadius: tvRadii.card_lg,
    alignSelf: 'center',
  },
  info: {
    alignItems: 'center',
    gap: tvSpacing.gap_sm,
  },
  title: {
    fontSize: tvFontSizes['2xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
    textAlign: 'center',
  },
  artist: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textSecondary,
    textAlign: 'center',
  },
  loadingText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
    textAlign: 'center',
    padding: tvSpacing.sectionPadding,
  },
});
