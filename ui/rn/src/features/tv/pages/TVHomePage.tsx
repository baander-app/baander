/**
 * TVHomePage -- home screen with four content sections.
 *
 * Sections:
 * - Featured/promoted content
 * - Continue listening- Recently added
 * - Discovery/recommendations
 *
 * Empty sections don't render (per plan decision).
 */

import React from 'react';
import { View, ScrollView, StyleSheet } from 'react-native';
import { TVContentRow } from '../components/TVContentRow';
import { TVCard } from '../components/TVCard';
import { TVHeroSection } from '../components/TVHeroSection';
import { useAlbums } from '@/features/catalog/hooks/useAlbums';
import { tvSpacing, tvColors } from '../theme/tv-tokens';
import { useNavigation } from '@react-navigation/native';

export function TVHomePage() {
  const navigation = useNavigation();

  // Fetch data for each section
  const { data: featuredAlbums } = useAlbums({ limit: 10 });
  const { data: recentAlbums } = useAlbums({ page: 1, limit: 10 });

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Hero section */}
      <TVHeroSection
        title="Featured Album"
        subtitle="Featured Artist"
        description="This week's top pick"
        primaryAction={{ label: 'Play', onPress: () => {} }}
        secondaryAction={{ label: 'Add to Library', onPress: () => {} }}
      />

      {/* Featured section */}
      {featuredAlbums && featuredAlbums.length > 0 && (
        <TVContentRow title="Featured" onViewAll={() => {}}>
          {featuredAlbums.map((album) => (
            <TVCard
              key={album.uuid}
              title={album.title}
              subtitle={album.artistName ?? undefined}
              onPress={() => navigation.navigate('TVAlbumDetail', { publicId: album.publicId })}
            />
          ))}
        </TVContentRow>
      )}

      {/* Recently added section */}
      {recentAlbums && recentAlbums.length > 0 && (
        <TVContentRow title="Recently Added" onViewAll={() => {}}>
          {recentAlbums.map((album) => (
            <TVCard
              key={album.uuid}
              title={album.title}
              subtitle={album.artistName ?? undefined}
              onPress={() => navigation.navigate('TVAlbumDetail', { publicId: album.publicId })}
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
  sectionGap: {
    height: tvSpacing.rowGap,
  },
});
