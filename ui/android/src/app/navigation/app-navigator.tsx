/**
 * App navigator -- bottom tabs + stack navigator.
 *
 * Tabs: Home (artists, albums, genres), Search, Playlists, Favorites, Settings.
 * Wraps MobileAppShell around all screens for mini-player + tab bar.
 */

import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import { MobileAppShell } from '@/features/mobile/components/MobileAppShell';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';

const Stack = createStackNavigator();

function PlaceholderPage({ title }: { title: string }) {
  return (
    <View style={styles.page}>
      <Text style={styles.text}>{title}</Text>
    </View>
  );
}

export function AppNavigator() {
  return (
    <MobileAppShell>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        <Stack.Screen name="Home" component={() => <PlaceholderPage title="Home" />} />
        <Stack.Screen name="Search" component={() => <PlaceholderPage title="Search" />} />
        <Stack.Screen name="Playlists" component={() => <PlaceholderPage title="Playlists" />} />
        <Stack.Screen name="Favorites" component={() => <PlaceholderPage title="Favorites" />} />
        <Stack.Screen name="Settings" component={() => <PlaceholderPage title="Settings" />} />
      </Stack.Navigator>
    </MobileAppShell>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.background,
    justifyContent: 'center',
    alignItems: 'center',
  },
  text: {
    color: colors.foreground,
    fontSize: 24,
  },
});
