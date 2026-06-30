/**
 * Server code authentication page.
 *
 * Two inputs: pairing code (e.g., BCDF-GHJK) and server public ID.
 * Uses the discovery endpoint to find the server and authenticate.
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
import { spacing, radii } from '@/shared/theme/tokens';

export function ServerCodePage() {
  const { loginViaServerCode, isLoading } = useAuthStore();
  const [pairingCode, setPairingCode] = useState('');
  const [serverPublicId, setServerPublicId] = useState('');
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async () => {
    setError(null);

    if (!pairingCode.trim()) {
      setError('Pairing code is required');
      return;
    }
    if (!serverPublicId.trim()) {
      setError('Server ID is required');
      return;
    }

    try {
      await loginViaServerCode(pairingCode.trim().toUpperCase(), serverPublicId.trim());
    } catch (err: any) {
      setError(err?.response?.data?.message ?? err?.message ?? 'Server code login failed');
    }
  };

  return (
    <>
      <TextInput
        style={styles.input}
        placeholder="Pairing code (e.g. BCDF-GHJK)"
        placeholderTextColor={colors.muted}
        value={pairingCode}
        onChangeText={(text) => setPairingCode(text.toUpperCase())}
        autoCapitalize="characters"
        autoCorrect={false}
        maxLength={9}
        editable={!isLoading}
      />
      <TextInput
        style={styles.input}
        placeholder="Server ID"
        placeholderTextColor={colors.muted}
        value={serverPublicId}
        onChangeText={setServerPublicId}
        autoCapitalize="none"
        autoCorrect={false}
        editable={!isLoading}
      />

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable
        style={[styles.button, isLoading && styles.buttonDisabled]}
        onPress={handleSubmit}
        disabled={isLoading}
      >
        {isLoading ? (
          <ActivityIndicator color={colors.foreground} />
        ) : (
          <Text style={styles.buttonText}>Connect</Text>
        )}
      </Pressable>
    </>
  );
}

const styles = StyleSheet.create({
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
