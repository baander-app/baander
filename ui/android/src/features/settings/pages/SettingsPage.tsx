/**
 * SettingsPage -- server URL, user email, app version, logout.
 *
 * Logout clears auth store.
 */

import React from 'react';
import { View, Text, Pressable, Alert, StyleSheet } from 'react-native';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface SettingsPageProps {
  onLogout: () => void;
}

export function SettingsPage({ onLogout }: SettingsPageProps) {
  const auth = getAuthSnapshot();
  const appVersion = '1.0.0';

  const handleLogout = () => {
    Alert.alert('Logout', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Logout',
        style: 'destructive',
        onPress: () => {
          auth.clearAuth();
          onLogout();
        },
      },
    ]);
  };

  return (
    <View style={styles.container}>
      {/* Server section */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Server</Text>
        <View style={styles.row}>
          <Text style={styles.rowLabel}>Server URL</Text>
          <Text style={styles.rowValue} numberOfLines={1}>
            {auth.serverUrl ?? 'Not connected'}
          </Text>
        </View>
      </View>

      {/* Account section */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Account</Text>
        <View style={styles.row}>
          <Text style={styles.rowLabel}>Email</Text>
          <Text style={styles.rowValue} numberOfLines={1}>
            {auth.user?.email ?? 'Not signed in'}
          </Text>
        </View>
        <View style={styles.row}>
          <Text style={styles.rowLabel}>Status</Text>
          <Text style={[styles.rowValue, auth.isAuthenticated && styles.activeStatus]}>
            {auth.isAuthenticated ? 'Authenticated' : 'Not authenticated'}
          </Text>
        </View>
      </View>

      {/* About section */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>About</Text>
        <View style={styles.row}>
          <Text style={styles.rowLabel}>App Version</Text>
          <Text style={styles.rowValue}>{appVersion}</Text>
        </View>
      </View>

      {/* Logout button */}
      <View style={styles.logoutContainer}>
        <Pressable style={styles.logoutButton} onPress={handleLogout}>
          <Text style={styles.logoutText}>Logout</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background, paddingTop: spacing[6] },
  section: { marginBottom: spacing[6], paddingHorizontal: spacing[4] },
  sectionTitle: {
    color: colors.muted, fontSize: fontSizes.xs, fontWeight: '600',
    textTransform: 'uppercase', marginBottom: spacing[2],
  },
  row: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingVertical: spacing[3], borderBottomWidth: 1, borderBottomColor: colors.border,
  },
  rowLabel: { color: colors.muted, fontSize: fontSizes.body },
  rowValue: {
    color: colors.foreground, fontSize: fontSizes.body,
    flex: 1, textAlign: 'right', marginLeft: spacing[4],
  },
  activeStatus: { color: '#22c55e' },
  logoutContainer: { marginTop: spacing[8], paddingHorizontal: spacing[4] },
  logoutButton: {
    backgroundColor: colors.destructive, paddingVertical: spacing[3],
    borderRadius: radii.md, alignItems: 'center',
  },
  logoutText: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '600' },
});
