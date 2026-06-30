/**
 * Mobile tab bar -- bottom navigation.
 *
 * Tabs: Home, Search, Library, Settings
 */

import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, sizes, fontSizes } from '@/shared/theme/tokens';

interface Tab {
  key: string;
  label: string;
}

const TABS: Tab[] = [
  { key: 'Home', label: 'Home' },
  { key: 'Search', label: 'Search' },
  { key: 'Library', label: 'Library' },
  { key: 'Settings', label: 'Settings' },
];

interface MobileTabBarProps {
  activeTab?: string;
  onTabPress?: (tab: string) => void;
}

export function MobileTabBar({ activeTab = 'Home', onTabPress }: MobileTabBarProps) {
  return (
    <View style={styles.container}>
      {TABS.map((tab) => (
        <Pressable
          key={tab.key}
          style={styles.tab}
          onPress={() => onTabPress?.(tab.key)}
        >
          <Text style={[styles.tabText, activeTab === tab.key && styles.tabTextActive]}>
            {tab.label}
          </Text>
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    height: sizes.mobileTabBarHeight,
    backgroundColor: colors.sidebar,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tabText: {
    color: colors.muted,
    fontSize: fontSizes.label,
    textTransform: 'uppercase',
    letterSpacing: 0.05,
  },
  tabTextActive: {
    color: colors.foreground,
  },
});
