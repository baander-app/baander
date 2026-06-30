/**
 * Register page -- email, password, optional name.
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

interface RegisterPageProps {
  onSwitchToLogin: () => void;
}

export function RegisterPage({ onSwitchToLogin }: RegisterPageProps) {
  const { register, isLoading } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleRegister = async () => {
    setError(null);
    setSuccess(false);
    try {
      await register(email, password, name || undefined);
      setSuccess(true);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err?.message ?? 'Registration failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Create Account</Text>

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
        placeholder="Display name (optional)"
        placeholderTextColor={colors.muted}
        value={name}
        onChangeText={setName}
        autoCapitalize="words"
        editable={!isLoading}
      />

      {error && <Text style={styles.error}>{error}</Text>}
      {success && <Text style={styles.success}>Account created. You can now sign in.</Text>}

      <Pressable
        style={[styles.button, isLoading && styles.buttonDisabled]}
        onPress={handleRegister}
        disabled={isLoading}
      >
        {isLoading ? (
          <ActivityIndicator color={colors.foreground} />
        ) : (
          <Text style={styles.buttonText}>Create account</Text>
        )}
      </Pressable>

      <Pressable onPress={onSwitchToLogin} disabled={isLoading}>
        <Text style={styles.link}>Already have an account? Sign in</Text>
      </Pressable>
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
  success: {
    color: '#22c55e',
    fontSize: 13,
  },
  link: {
    color: colors.primary,
    fontSize: 13,
    textAlign: 'center',
  },
});
