/**
 * TVArtistDetailPage -- artist detail page.
 *
 * Shows artist image, bio, album rows, and popular tracks.
 */

import React from 'react';
import { View, ScrollView, StyleSheet } from 'react-native';
import { useRoute, RouteProp } from '@react-navigation/native';
import { Image, Text } from 'react-native';
import { TVContentRow } from '../components/TVContentRow';
import { TVCard } from '../components/TVCard';
import { TVTrackRow } from '../components/TVTrackRow';
import { useAlbums } from '@/features/catalog/hooks/useAlbums';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';
import type { TVRouteParams } from '../navigation/TVRoutes';

type ArtistDetailRoute = RouteProp<TVRouteParams, 'TVArtistDetail'>;

export function TVArtistDetailPage() {
  const route = useRoute<ArtistDetailRoute>();
  const { publicId } = route.params;

  const { data: albums } = useAlbums({ limit: 20 });

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Artist image */}
      <View style={styles.header}>
        <Image
          source={{ uri: 'https://via.placeholder.com/400' }}
          style={styles.image}
          resizeMode="cover"
        />
        <Text style={styles.name}>Artist Name</Text>
      </View>

      {/* Albums */}
      {albums && albums.length > 0 && (
        <TVContentRow title="Albums" onViewAll={() => {}}>
          {albums.map((album) => (
            <TVCard
              key={album.uuid}
              title={album.title}
              subtitle={album.releaseYear?.toString()}
              onPress={() => {}}
            />
          ))}
        </TVContentRow>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  header: {
    padding: tvSpacing.sectionPaddingLarge,
    alignItems: 'center',
    gap: tvSpacing.gap_md,
  },
  image: {
    width: tvSizes.cardHeroWidth,
    height: tvSizes.cardHeroWidth,
    borderRadius: tvRadii.card_lg,
  },
  name: {
    fontSize: tvFontSizes['2xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
});
