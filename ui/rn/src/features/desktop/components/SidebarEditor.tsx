/**
 * Sidebar Editor -- modal for customizing sidebar sections.
 *
 * Allows toggling which nav sections appear and reordering.
 */

import React, { useState } from 'react';
import { View, Text, Pressable, FlatList, StyleSheet } from 'react-native';
import { Sheet } from '@/shared/components/ui/sheet';
import { Switch } from '@/shared/components/ui/switch';
import { useSidebarStore } from '../stores/sidebar-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface SidebarSection {
  id: string;
  label: string;
  enabled: boolean;
}

const DEFAULT_SECTIONS: SidebarSection[] = [
  { id: 'home', label: 'Home', enabled: true },
  { id: 'albums', label: 'Albums', enabled: true },
  { id: 'artists', label: 'Artists', enabled: true },
  { id: 'songs', label: 'Songs', enabled: true },
  { id: 'genres', label: 'Genres', enabled: true },
  { id: 'playlists', label: 'Playlists', enabled: true },
  { id: 'radio', label: 'Radio', enabled: false },
];

export function SidebarEditor() {
  const { editorOpen, setEditorOpen } = useSidebarStore();
  const [sections, setSections] = useState(DEFAULT_SECTIONS);

  const toggleSection = (id: string) => {
    setSections((prev) =>
      prev.map((s) => (s.id === id ? { ...s, enabled: !s.enabled } : s)),
    );
  };

  return (
    <Sheet open={editorOpen} onClose={() => setEditorOpen(false)}>
      <Text style={styles.title}>Customize Sidebar</Text>
      <FlatList
        data={sections}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <View style={styles.row}>
            <Text style={styles.label}>{item.label}</Text>
            <Switch value={item.enabled} onValueChange={() => toggleSection(item.id)} />
          </View>
        )}
      />
      <Pressable style={styles.closeButton} onPress={() => setEditorOpen(false)}>
        <Text style={styles.closeText}>Done</Text>
      </Pressable>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  title: {
    color: colors.foreground,
    fontSize: fontSizes.lg,
    fontWeight: '600',
    marginBottom: spacing[4],
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: spacing[2],
  },
  label: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  closeButton: {
    marginTop: spacing[4],
    alignSelf: 'flex-end',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    backgroundColor: colors.primary,
    borderRadius: radii.md,
  },
  closeText: {
    color: colors.foreground,
    fontSize: fontSizes.body,
    fontWeight: '500',
  },
});
