import React, { useState } from 'react';
import { View, Text, TextInput, ScrollView, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileSearchPage() {
  const [query, setQuery] = useState('');

  return (
    <View style={styles.container}>
      {/* Search bar */}
      <View style={styles.searchWrap}>
        <TextInput
          style={styles.searchInput}
          placeholder="Search songs, albums, artists..."
          placeholderTextColor={colors.muted}
          value={query}
          onChangeText={setQuery}
          autoFocus
        />
      </View>

      <ScrollView style={styles.results} contentContainerStyle={styles.resultsContent}>
        {!query ? (
          <View style={styles.empty}>
            <Text style={styles.emptyText}>Type to search</Text>
          </View>
        ) : (
          <>
            <Text style={styles.sectionTitle}>Albums</Text>
            <Text style={styles.sectionTitle}>Artists</Text>
            <Text style={styles.sectionTitle}>Songs</Text>
          </>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  searchWrap: {
    padding: spacing[3],
  },
  searchInput: {
    backgroundColor: colors.card,
    color: colors.foreground,
    borderRadius: radii.full,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    fontSize: fontSizes.body,
  },
  results: {
    flex: 1,
  },
  resultsContent: {
    paddingBottom: 120,
  },
  empty: {
    paddingVertical: spacing[8],
    alignItems: 'center',
  },
  emptyText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  sectionTitle: {
    color: colors.foreground,
    fontSize: fontSizes.lg,
    fontWeight: '600',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
  },
});
