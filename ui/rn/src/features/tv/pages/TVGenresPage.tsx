/**
 * TVGenresPage -- genre selection and browsing.
 *
 * Shows genre chips in horizontal row.
 * Selecting opens filtered content.
 */

import React from 'react';
import { View, ScrollView, StyleSheet } from 'react-native';
import { TVFocusable } from '../components/TVFocusable';
import { Text } from 'react-native';
import { useGenres } from '@/features/catalog/hooks/useGenres';
import { tvColors, tvFontSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';

export function TVGenresPage() {
  const { data: genres } = useGenres();

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      <View style={styles.chips}>
        {genres?.map((genre) => (
          <TVFocusable key={genre.uuid} style={styles.chip} onPress={() => {}}>
            <Text style={styles.chipText}>{genre.name}</Text>
          </TVFocusable>
        ))}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    padding: tvSpacing.sectionPadding,
    gap: tvSpacing.gap_md,
  },
  chip: {
    paddingHorizontal: tvSpacing.gap_lg,
    paddingVertical: tvSpacing.gap_md,
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_md,
    borderWidth: 1,
    borderColor: tvColors.border,
  },
  chipText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
});
