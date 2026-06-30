/**
 * Desktop navigator -- stack navigator for desktop content area.
 *
 * Wraps DesktopAppShell around all screens.
 * The sidebar and context panel are part of the shell, not the navigation stack.
 */

import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import { DesktopAppShell } from '@/features/desktop/components/DesktopAppShell';
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

export function DesktopNavigator() {
  return (
    <DesktopAppShell>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        <Stack.Screen name="Home" component={() => <PlaceholderPage title="Home" />} />
        <Stack.Screen name="Albums" component={() => <PlaceholderPage title="Albums" />} />
        <Stack.Screen name="Artists" component={() => <PlaceholderPage title="Artists" />} />
        <Stack.Screen name="Songs" component={() => <PlaceholderPage title="Songs" />} />
        <Stack.Screen name="Search" component={() => <PlaceholderPage title="Search" />} />
        <Stack.Screen name="Settings" component={() => <PlaceholderPage title="Settings" />} />
      </Stack.Navigator>
    </DesktopAppShell>
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
