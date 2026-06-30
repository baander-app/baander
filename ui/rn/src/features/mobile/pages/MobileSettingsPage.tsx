import React from 'react';
import { View, Text, ScrollView, Pressable, StyleSheet } from 'react-native';
import { Switch } from '@/shared/components/ui/switch';
import { Separator } from '@/shared/components/ui/separator';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

export function MobileSettingsPage() {
  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Settings</Text>

      {/* Playback */}
      <Text style={styles.sectionLabel}>Playback</Text>
      <SettingRow label="Gapless playback" />
      <SettingRow label="Crossfade" />
      <Separator />
      <SettingRow label="Audio quality" value="High" />

      {/* Appearance */}
      <Text style={styles.sectionLabel}>Appearance</Text>
      <SettingRow label="Theme" value="Dark" />

      {/* Server */}
      <Text style={styles.sectionLabel}>Server</Text>
      <SettingRow label="Server URL" value="Configure" />

      {/* About */}
      <Text style={styles.sectionLabel}>About</Text>
      <SettingRow label="Version" value="0.1.0" />
    </ScrollView>
  );
}

function SettingRow({ label, value }: { label: string; value?: string }) {
  return (
    <Pressable style={styles.settingRow}>
      <Text style={styles.settingLabel}>{label}</Text>
      {value && <Text style={styles.settingValue}>{value}</Text>}
    </Pressable>
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
  sectionLabel: {
    color: colors.muted,
    fontSize: fontSizes.label,
    textTransform: 'uppercase',
    letterSpacing: 0.05,
    paddingHorizontal: spacing[4],
    paddingTop: spacing[4],
    paddingBottom: spacing[2],
  },
  settingRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
  },
  settingLabel: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  settingValue: {
    color: colors.muted,
    fontSize: fontSizes.body,
  },
});
