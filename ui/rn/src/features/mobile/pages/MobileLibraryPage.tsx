import React from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileLibraryPage() {
  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Library</Text>

      {/* Filter chips */}
      <View style={styles.filters}>
        {['Albums', 'Artists', 'Songs', 'Playlists'].map((filter) => (
          <Pressable key={filter} style={styles.filterChip}>
            <Text style={styles.filterText}>{filter}</Text>
          </Pressable>
        ))}
      </View>

      {/* Library items */}
      {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
        <Pressable key={i} style={styles.itemRow}>
          <View style={styles.itemArt} />
          <View style={styles.itemInfo}>
            <Text style={styles.itemTitle} numberOfLines={1}>Item {i}</Text>
            <Text style={styles.itemMeta} numberOfLines={1}>Artist · Album</Text>
          </View>
        </Pressable>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    paddingBottom: 120,
  },
  title: {
    color: colors.foreground,
    fontSize: fontSizes.xl,
    fontWeight: '600',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[4],
  },
  filters: {
    flexDirection: 'row',
    paddingHorizontal: spacing[3],
    marginBottom: spacing[4],
    gap: spacing[2],
  },
  filterChip: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1.5],
    borderRadius: radii.full,
    backgroundColor: colors.card,
  },
  filterText: {
    color: colors.foreground,
    fontSize: fontSizes.sm,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    gap: spacing[3],
  },
  itemArt: {
    width: 48,
    height: 48,
    borderRadius: radii.md,
    backgroundColor: colors.card,
  },
  itemInfo: {
    flex: 1,
    minWidth: 0,
  },
  itemTitle: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
  itemMeta: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
