/**
 * Context Panel -- slide-in panel for queue, lyrics, details.
 *
 * Modes: collapsed (hidden) | compact (280px) | expanded (360px)
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { useContextPanelStore, type ContextPanelTab } from '../stores/context-panel-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

const TABS: { key: ContextPanelTab; label: string }[] = [
  { key: 'queue', label: 'Queue' },
  { key: 'lyrics', label: 'Lyrics' },
  { key: 'details', label: 'Details' },
];

export function ContextPanel() {
  const { mode, activeTab, setActiveTab, setMode } = useContextPanelStore();

  if (mode === 'collapsed') return null;

  const width = mode === 'compact' ? 280 : 360;

  return (
    <View style={[styles.container, { width }]}>
      {/* Header */}
      <View style={styles.header}>
        {TABS.map((tab) => (
          <Pressable
            key={tab.key}
            style={[styles.tab, activeTab === tab.key && styles.tabActive]}
            onPress={() => setActiveTab(tab.key)}
          >
            <Text style={[styles.tabText, activeTab === tab.key && styles.tabTextActive]}>
              {tab.label}
            </Text>
          </Pressable>
        ))}
      </View>

      {/* Content placeholder */}
      <View style={styles.content}>
        <Text style={styles.placeholder}>No {activeTab} data</Text>
      </View>

      {/* Close button */}
      <Pressable style={styles.closeButton} onPress={() => setMode('collapsed')}>
        <Text style={styles.closeText}>Close</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.card,
    borderLeftWidth: 1,
    borderLeftColor: colors.border,
    flexDirection: 'column',
  },
  header: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[2],
    gap: spacing[2],
  },
  tab: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[1],
    borderRadius: radii.md,
  },
  tabActive: {
    backgroundColor: colors.secondary,
  },
  tabText: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
  tabTextActive: {
    color: colors.foreground,
  },
  content: {
    flex: 1,
    padding: spacing[4],
    justifyContent: 'center',
    alignItems: 'center',
  },
  placeholder: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
  closeButton: {
    padding: spacing[3],
    alignItems: 'center',
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  closeText: {
    color: colors.muted,
    fontSize: fontSizes.sm,
  },
});
