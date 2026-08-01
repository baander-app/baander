/**
 * Login page -- tab-based auth method selector.
 *
 * Three methods:
 * 1. QR Code -- camera scanner
 * 2. Email + URL -- direct login
 * 3. Server Code -- pairing code + server ID
 */

import React, { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  Pressable,
  StyleSheet,
  ActivityIndicator,
} from 'react-native';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { QRScannerPage } from './QRScannerPage';
import { ServerCodePage } from './ServerCodePage';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

type AuthMethod = 'qr' | 'email' | 'server_code';

const AUTH_TABS: { key: AuthMethod; label: string }[] = [
  { key: 'qr', label: 'QR Code' },
  { key: 'email', label: 'Email' },
  { key: 'server_code', label: 'Server Code' },
];

export function LoginPage() {
  const [activeMethod, setActiveMethod] = useState<AuthMethod>('email');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [serverUrl, setServerUrl] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { login, isLoading, setServerUrl: storeServerUrl } = useAuthStore();

  const handleEmailLogin = async () => {
    setError(null);
    try {
      if (serverUrl) {
        storeServerUrl(serverUrl);
      }
      await login(email, password, totpCode || undefined);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err?.message ?? 'Login failed');
    }
  };

  const renderForm = () => {
    switch (activeMethod) {
      case 'qr':
        return <QRScannerPage />;

      case 'server_code':
        return <ServerCodePage />;

      case 'email':
      default:
        return (
          <>
            <TextInput
              style={styles.input}
              placeholder="Server URL (e.g. https://baander.local)"
              placeholderTextColor={colors.muted}
              value={serverUrl}
              onChangeText={setServerUrl}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              editable={!isLoading}
            />
            <TextInput
              style={styles.input}
              placeholder="Email"
              placeholderTextColor={colors.muted}
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              editable={!isLoading}
            />
            <TextInput
              style={styles.input}
              placeholder="Password"
              placeholderTextColor={colors.muted}
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              editable={!isLoading}
            />
            <TextInput
              style={styles.input}
              placeholder="TOTP code (optional)"
              placeholderTextColor={colors.muted}
              value={totpCode}
              onChangeText={setTotpCode}
              keyboardType="number-pad"
              maxLength={6}
              editable={!isLoading}
            />

            {error && <Text style={styles.error}>{error}</Text>}

            <Pressable
              style={[styles.button, isLoading && styles.buttonDisabled]}
              onPress={handleEmailLogin}
              disabled={isLoading}
            >
              {isLoading ? (
                <ActivityIndicator color={colors.foreground} />
              ) : (
                <Text style={styles.buttonText}>Sign in</Text>
              )}
            </Pressable>
          </>
        );
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Baander</Text>

      {/* Auth method tabs */}
      <View style={styles.tabRow}>
        {AUTH_TABS.map((tab) => (
          <Pressable
            key={tab.key}
            style={[styles.tab, activeMethod === tab.key && styles.tabActive]}
            onPress={() => {
              setError(null);
              setActiveMethod(tab.key);
            }}
          >
            <Text style={[styles.tabText, activeMethod === tab.key && styles.tabTextActive]}>
              {tab.label}
            </Text>
          </Pressable>
        ))}
      </View>

      {/* Form area */}
      {renderForm()}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
    padding: spacing[6],
    justifyContent: 'center',
    gap: spacing[3],
  },
  title: {
    color: colors.foreground,
    fontSize: 32,
    fontWeight: '600',
    textAlign: 'center',
    marginBottom: spacing[8],
  },
  tabRow: {
    flexDirection: 'row',
    backgroundColor: colors.card,
    borderRadius: radii.lg,
    marginBottom: spacing[4],
    overflow: 'hidden',
  },
  tab: {
    flex: 1,
    paddingVertical: spacing[3],
    alignItems: 'center',
  },
  tabActive: {
    backgroundColor: colors.primary,
  },
  tabText: {
    color: colors.muted,
    fontSize: 13,
    fontWeight: '500',
  },
  tabTextActive: {
    color: colors.foreground,
  },
  input: {
    backgroundColor: colors.card,
    color: colors.foreground,
    borderRadius: radii.lg,
    paddingVertical: spacing[3],
    paddingHorizontal: spacing[4],
    fontSize: 14,
  },
  button: {
    backgroundColor: colors.primary,
    borderRadius: radii.lg,
    paddingVertical: spacing[3],
    alignItems: 'center',
    marginTop: spacing[2],
  },
  buttonDisabled: {
    opacity: 0.5,
  },
  buttonText: {
    color: colors.foreground,
    fontSize: 14,
    fontWeight: '600',
  },
  error: {
    color: colors.destructive,
    fontSize: 13,
  },
});
