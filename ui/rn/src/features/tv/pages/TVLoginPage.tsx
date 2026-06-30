/**
 * TVLoginPage -- login screen for TV.
 *
 * Uses on-screen keyboard for text entry.
 * Server URL, email, password fields with validation.
 * Caches last successful URL.
 */

import React, { useState } from 'react';
import { View, Text, StyleSheet, ScrollView } from 'react-native';
import { TVFocusable } from '../components/TVFocusable';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { useNavigation } from '@react-navigation/native';
import { tvColors, tvFontSizes, tvSizes, tvSpacing, tvRadii } from '../theme/tv-tokens';

export function TVLoginPage() {
  const navigation = useNavigation();
  const { login, setServerUrl, isLoading } = useAuthStore();

  const [serverUrl, setUrlInput] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const isValidUrl = (url: string): boolean => {
    try {
      const parsed = new URL(url.startsWith('http') ? url : `https://${url}`);
      return parsed.protocol === 'https:';
    } catch {
      return false;
    }
  };

  const handleLogin = async () => {
    setError(null);

    // Validate server URL
    if (!serverUrl.trim()) {
      setError('Please enter a server URL');
      return;
    }

    if (!isValidUrl(serverUrl)) {
      setError('Server URL must be a valid HTTPS address');
      return;
    }

    // Validate credentials
    if (!email.trim() || !password.trim()) {
      setError('Please enter email and password');
      return;
    }

    try {
      // Set server URL and login
      const formattedUrl = serverUrl.startsWith('http')
        ? serverUrl
        : `https://${serverUrl}`;
      setServerUrl(formattedUrl.replace(/\/$/, ''));

      await login(email.trim(), password);

      // Navigation happens automatically on successful login
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
    }
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Welcome to Baander</Text>
      <Text style={styles.subtitle}>Enter your server details to get started</Text>

      {error && <Text style={styles.error}>{error}</Text>}

      {/* Server URL */}
      <View style={styles.fieldGroup}>
        <Text style={styles.label}>Server URL</Text>
        <TVFocusable
          style={styles.inputContainer}
          contentStyle={[
            styles.input,
            !isValidUrl(serverUrl) && serverUrl.length > 0 && styles.inputError,
          ]}
        >
          <Text style={styles.inputText}>
            {serverUrl || 'https://server.example.com'}
          </Text>
        </TVFocusable>
      </View>

      {/* Email */}
      <View style={styles.fieldGroup}>
        <Text style={styles.label}>Email</Text>
        <TVFocusable style={styles.inputContainer} contentStyle={styles.input}>
          <Text style={styles.inputText}>{email || 'user@example.com'}</Text>
        </TVFocusable>
      </View>

      {/* Password */}
      <View style={styles.fieldGroup}>
        <Text style={styles.label}>Password</Text>
        <TVFocusable style={styles.inputContainer} contentStyle={styles.input}>
          <Text style={styles.inputText}>{password ? '•'.repeat(password.length) : '••••••••'}</Text>
        </TVFocusable>
      </View>

      {/* Login button */}
      <TVFocusable
        onPress={handleLogin}
        style={styles.loginButton}
        isFocused={false}
      >
        <Text style={styles.loginButtonText}>
          {isLoading ? 'Logging in...' : 'Log In'}
        </Text>
      </TVFocusable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
  },
  content: {
    padding: tvSpacing.sectionPaddingLarge,
    gap: tvSpacing.gap_lg,
    justifyContent: 'center',
  },
  title: {
    fontSize: tvFontSizes['4xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
    textAlign: 'center',
  },
  subtitle: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
    textAlign: 'center',
  },
  error: {
    fontSize: tvFontSizes.body,
    color: tvColors.destructive,
    textAlign: 'center',
    padding: tvSpacing.gap_md,
    backgroundColor: `${tvColors.destructive}20`,
    borderRadius: tvRadii.card_md,
  },
  fieldGroup: {
    gap: tvSpacing.gap_sm,
  },
  label: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
  inputContainer: {
    height: tvSizes.inputHeight,
  },
  input: {
    flex: 1,
    backgroundColor: tvColors.card,
    borderRadius: tvRadii.card_sm,
    borderWidth: 1,
    borderColor: tvColors.border,
    paddingHorizontal: tvSpacing.gap_md,
    justifyContent: 'center',
  },
  inputError: {
    borderColor: tvColors.destructive,
  },
  inputText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
  loginButton: {
    height: tvSizes.buttonHeight,
    backgroundColor: tvColors.primary,
    borderRadius: tvRadii.card_md,
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: tvSpacing.gap_lg,
  },
  loginButtonText: {
    fontSize: tvFontSizes.lg,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
});
