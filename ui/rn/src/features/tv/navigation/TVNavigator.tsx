/**
 * TV navigator -- stack navigator for TV screens.
 *
 * Uses React Navigation Stack with TV-optimized transitions.
 * Full-screen card style for all screens (no header, no modal-like presentation).
 *
 * TV-specific behaviors:
 * - D-pad back button uses goBack()
 * - Focus restoration on back navigation
 * - All screens render with TVAppShell wrapper
 */

import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import { TVAppShell } from '../components/TVAppShell';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors } from '../theme/tv-tokens';
import type { TVRouteName } from './TVRoutes';

// Catalog pages
import { TVHomePage } from '../pages/TVHomePage';
import { TVAlbumDetailPage } from '../pages/TVAlbumDetailPage';
import { TVArtistDetailPage } from '../pages/TVArtistDetailPage';
import { TVSearchPage } from '../pages/TVSearchPage';
import { TVGenresPage } from '../pages/TVGenresPage';
import { TVLoginPage } from '../pages/TVLoginPage';
import { TVAdminDashboardPage } from '../pages/TVAdminDashboardPage';
import { TVJobMonitorPage } from '../pages/TVJobMonitorPage';
import { TVRateLimitersPage } from '../pages/TVRateLimitersPage';
import { TVServerDiagnosticsPage } from '../pages/TVServerDiagnosticsPage';
import { TVConfigurationPage } from '../pages/TVConfigurationPage';
import { TVUsersPage } from '../pages/TVUsersPage';
import { TVActivityPage } from '../pages/TVActivityPage';
import { TVGenresAdminPage } from '../pages/TVGenresPage-Admin';
import { TVMetadataPage } from '../pages/TVMetadataPage';
import { TVRecommendationsPage } from '../pages/TVRecommendationsPage';
import { TVTranscodePage } from '../pages/TVTranscodePage';
import { TVLyricsAdminPage } from '../pages/TVLyricsAdminPage';
import { TVAlbumDuplicatesPage } from '../pages/TVAlbumDuplicatesPage';
import { TVAdminQoLPage } from '../pages/TVAdminQoLPage';

const Stack = createStackNavigator();

/**
 * Placeholder screen -- used for admin and login pages (not yet implemented).
 */
function PlaceholderScreen({ routeName }: { routeName: TVRouteName }) {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>{routeName}</Text>
      <Text style={styles.subtext}>Coming soon</Text>
    </View>
  );
}

export function TVNavigator() {
  return (
    <TVAppShell>
      <Stack.Navigator
        screenOptions={{
          headerShown: false,
          cardStyle: { backgroundColor: tvColors.background },
          cardStyleInterpolator: ({ current, layouts }) => {
            return {
              cardStyle: {
                transform: [
                  {
                    translateX: current.progress.interpolate({
                      inputRange: [0, 1],
                      outputRange: [layouts.screen.width, 0],
                    }),
                  },
                ],
              },
            };
          },
        }}
      >
        <Stack.Screen name="TVHome" component={TVHomePage} />
        <Stack.Screen name="TVAlbumDetail" component={TVAlbumDetailPage} />
        <Stack.Screen name="TVArtistDetail" component={TVArtistDetailPage} />
        <Stack.Screen name="TVSearch" component={TVSearchPage} />
        <Stack.Screen name="TVGenres" component={TVGenresPage} />

        {/* Admin pages */}
        <Stack.Screen name="TVAdminDashboard" component={TVAdminDashboardPage} />
        <Stack.Screen name="TVAdminJobs" component={TVJobMonitorPage} />
        <Stack.Screen name="TVAdminRateLimiters" component={TVRateLimitersPage} />
        <Stack.Screen name="TVAdminDiagnostics" component={TVServerDiagnosticsPage} />
        <Stack.Screen name="TVAdminConfiguration" component={TVConfigurationPage} />
        <Stack.Screen name="TVAdminUsers" component={TVUsersPage} />
        <Stack.Screen name="TVAdminActivity" component={TVActivityPage} />
        <Stack.Screen name="TVAdminGenres" component={TVGenresAdminPage} />
        <Stack.Screen name="TVAdminMetadata" component={TVMetadataPage} />
        <Stack.Screen name="TVAdminRecommendations" component={TVRecommendationsPage} />
        <Stack.Screen name="TVAdminTranscode" component={TVTranscodePage} />
        <Stack.Screen name="TVAdminLyrics" component={TVLyricsAdminPage} />
        <Stack.Screen name="TVAdminQoL" component={TVAdminQoLPage} />
        <Stack.Screen name="TVAdminDuplicates" component={TVAlbumDuplicatesPage} />

        {/* Login */}
        <Stack.Screen name="TVLogin" component={TVLoginPage} />
      </Stack.Navigator>
    </TVAppShell>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
    justifyContent: 'center',
    alignItems: 'center',
  },
  text: {
    color: tvColors.textPrimary,
    fontSize: 48,
    fontWeight: 'bold',
  },
  subtext: {
    color: tvColors.textMuted,
    fontSize: 28,
    marginTop: 16,
  },
});
