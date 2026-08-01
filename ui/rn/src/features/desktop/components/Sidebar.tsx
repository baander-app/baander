/**
 * Desktop Sidebar -- persistent navigation panel.
 *
 * Structure: Logo | Search | Media Selector | Nav Content | Pinned Footer | Settings
 */

import React, { useState } from 'react';
import { View, Text, TextInput, Pressable, ScrollView, StyleSheet } from 'react-native';
import { useSidebarStore } from '../stores/sidebar-store';
import { useMediaModeStore, type MediaType } from '../stores/media-mode-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

const MEDIA_TYPES: MediaType[] = ['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'];

const NAV_ITEMS: Record<MediaType, string[]> = {
  music: ['Home', 'Albums', 'Artists', 'Songs', 'Genres', 'Playlists', 'Radio'],
  movies: ['Home', 'Movies', 'Collections'],
  tv: ['Home', 'Shows', 'Episodes'],
  podcasts: ['Home', 'Subscriptions', 'Episodes'],
  concerts: ['Home', 'Concerts'],
  ebooks: ['Home', 'Books'],
};

export function Sidebar() {
  const [query, setQuery] = useState('');
  const { isCollapsed } = useSidebarStore();
  const { activeMedia, setActiveMedia } = useMediaModeStore();

  if (isCollapsed) {
    return (
      <View style={[styles.container, styles.collapsed]}>
        <Text style={styles.logoSmall}>B</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* Logo */}
      <View style={styles.logoRow}>
        <Text style={styles.logo}>Bander</Text>
      </View>

      {/* Search */}
      <View style={styles.searchWrap}>
        <TextInput
          style={styles.searchInput}
          placeholder="Search..."
          placeholderTextColor={colors.muted}
          value={query}
          onChangeText={setQuery}
        />
      </View>

      {/* Media Type Selector */}
      <View style={styles.mediaSelector}>
        {MEDIA_TYPES.map((type) => (
          <Pressable
            key={type}
            style={[styles.mediaTab, activeMedia === type && styles.mediaTabActive]}
            onPress={() => setActiveMedia(type)}
          >
            <Text style={[styles.mediaTabText, activeMedia === type && styles.mediaTabTextActive]}>
              {type.charAt(0).toUpperCase() + type.slice(1)}
            </Text>
          </Pressable>
        ))}
      </View>

      {/* Nav Content */}
      <ScrollView style={styles.navContent} contentContainerStyle={styles.navContentInner}>
        {NAV_ITEMS[activeMedia].map((item) => (
          <Pressable key={item} style={styles.navItem}>
            <Text style={styles.navItemText}>{item}</Text>
          </Pressable>
        ))}
      </ScrollView>

      {/* Footer */}
      <View style={styles.footer}>
        <Pressable style={styles.navItem}>
          <Text style={styles.navItemText}>Settings</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: sizes.sidebarExpanded,
    backgroundColor: colors.sidebar,
    borderRightWidth: 1,
    borderRightColor: colors.border,
    flexDirection: 'column',
  },
  collapsed: {
    width: sizes.sidebarCollapsed,
    alignItems: 'center',
    paddingTop: spacing[4],
  },
  logoRow: {
    height: sizes.headerHeight,
    paddingHorizontal: spacing[4],
    justifyContent: 'center',
  },
  logo: {
    color: colors.foreground,
    fontSize: 16,
    fontWeight: '600',
    letterSpacing: -0.01,
  },
  logoSmall: {
    color: colors.foreground,
    fontSize: 18,
    fontWeight: '600',
  },
  searchWrap: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[2],
  },
  searchInput: {
    backgroundColor: colors.card,
    color: colors.foreground,
    borderRadius: radii.lg,
    height: 28,
    paddingHorizontal: spacing[3],
    fontSize: 12,
  },
  mediaSelector: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: spacing[2],
    gap: spacing[1],
    marginBottom: spacing[2],
  },
  mediaTab: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[1],
    borderRadius: radii.md,
  },
  mediaTabActive: {
    backgroundColor: colors.primary,
  },
  mediaTabText: {
    color: colors.muted,
    fontSize: fontSizes.label,
    textTransform: 'uppercase',
    letterSpacing: 0.05,
  },
  mediaTabTextActive: {
    color: colors.foreground,
  },
  navContent: {
    flex: 1,
  },
  navContentInner: {
    paddingHorizontal: spacing[2],
    gap: spacing[0.5],
  },
  navItem: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[1.5],
    borderRadius: radii.md,
  },
  navItemText: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  footer: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[2],
  },
});
