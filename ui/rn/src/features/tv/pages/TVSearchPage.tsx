/**
 * TVSearchPage -- search screen with keyboard and results.
 *
 * Auto-focuses keyboard on load.
 * Real-time results with 300ms debounce.
 * Keyboard dismisses on selection.
 */

import React, { useState } from 'react';
import { View, ScrollView, StyleSheet, TextInput } from 'react-native';
import { TVCard } from '../components/TVCard';
import { useSearch } from '@/features/catalog/hooks/useSearch';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import { useNavigation } from '@react-navigation/native';

export function TVSearchPage() {
  const navigation = useNavigation();
  const [query, setQuery] = useState('');
  const { data, isLoading } = useSearch({ query, debounceMs: 300 });

  return (
    <View style={styles.container}>
      {/* Search input */}
      <View style={styles.searchContainer}>
        <TextInput
          style={styles.input}
          placeholder="Search albums, artists, songs..."
          placeholderTextColor={tvColors.textMuted}
          value={query}
          onChangeText={setQuery}
          autoFocus
          autoCapitalize="none"
          autoCorrect={false}
        />
      </View>

      {/* Results */}
      <ScrollView style={styles.results} showsVerticalScrollIndicator={false}>
        {isLoading ? (
          <></>
        ) : data ? (
          <>
            {data.albums.map((album) => (
              <TVCard
                key={album.uuid}
                title={album.title}
                subtitle={album.artistName ?? undefined}
                onPress={() => navigation.navigate('TVAlbumDetail', { publicId: album.publicId })}
              />
            ))}
            {data.artists.map((artist) => (
              <TVCard
                key={artist.uuid}
                title={artist.name}
                subtitle={`${artist.albumCount} albums`}
                onPress={() => navigation.navigate('TVArtistDetail', { publicId: artist.publicId })}
              />
            ))}
          </>
        ) : (
          <></>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  searchContainer: {
    padding: tvSpacing.sectionPadding,
    borderBottomWidth: 1,
    borderBottomColor: tvColors.border,
  },
  input: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    backgroundColor: tvColors.card,
    borderRadius: 8,
    paddingHorizontal: tvSpacing.gap_md,
    paddingVertical: tvSpacing.gap_sm,
    height: tvSizes.inputHeight,
  },
  results: {
    flex: 1,
    padding: tvSpacing.sectionPadding,
  },
});
