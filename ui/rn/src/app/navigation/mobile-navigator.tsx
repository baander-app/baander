/**
 * Mobile navigator -- bottom tabs + stack navigator.
 *
 * Wraps MobileAppShell around all screens.
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

export function MobileNavigator() {
  return (
    <MobileAppShell>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        <Stack.Screen name="Home" component={() => <PlaceholderPage title="Home" />} />
        <Stack.Screen name="Search" component={() => <PlaceholderPage title="Search" />} />
        <Stack.Screen name="Library" component={() => <PlaceholderPage title="Library" />} />
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
