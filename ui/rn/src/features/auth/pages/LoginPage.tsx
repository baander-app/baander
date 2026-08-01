/**
 * Login page -- server URL input, email/password, optional TOTP.
 *
 * Used by all three UIs (desktop, mobile, TV) with different layouts.
 * Each UI layer wraps this with its own container/keyboard handling.
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
import { colors } from '@/shared/theme/colors';
import { spacing } from '@/shared/theme/tokens';
import { radii } from '@/shared/theme/tokens';

interface LoginPageProps {
  onSwitchToRegister: () => void;
}

export function LoginPage({ onSwitchToRegister }: LoginPageProps) {
  const { login, isLoading, serverUrl, setServerUrl } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [error, setError] = useState<string | null>(null);

  const handleLogin = async () => {
    setError(null);
    try {
      await login(email, password, totpCode || undefined);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err?.message ?? 'Login failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Baander</Text>

      {!serverUrl && (
        <TextInput
          style={styles.input}
          placeholder="Server URL (e.g. https://baander.local)"
          placeholderTextColor={colors.muted}
          value={serverUrl ?? ''}
          onChangeText={setServerUrl}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          editable={!isLoading}
        />
      )}

      {serverUrl && (
        <>
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
            onPress={handleLogin}
            disabled={isLoading}
          >
            {isLoading ? (
              <ActivityIndicator color={colors.foreground} />
            ) : (
              <Text style={styles.buttonText}>Sign in</Text>
            )}
          </Pressable>

          <Pressable onPress={onSwitchToRegister} disabled={isLoading}>
            <Text style={styles.link}>Create an account</Text>
          </Pressable>

          <Pressable onPress={() => setServerUrl('')} disabled={isLoading}>
            <Text style={styles.link}>Change server</Text>
          </Pressable>
        </>
      )}

      {serverUrl && (
        <Text style={styles.serverLabel}>{serverUrl}</Text>
      )}
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
  link: {
    color: colors.primary,
    fontSize: 13,
    textAlign: 'center',
  },
  serverLabel: {
    color: colors.muted,
    fontSize: 11,
    textAlign: 'center',
    marginTop: spacing[4],
  },
});
