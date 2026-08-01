/**
 * QR code scanner page.
 *
 * Uses react-native-camera-kit to scan Baander pairing QR codes.
 * Expected QR format: baander://pair?server={publicId}&code={pairingCode}
 */

import React, { useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ActivityIndicator,
  Pressable,
} from 'react-native';
import { CameraKitCameraScreen } from 'react-native-camera-kit';
import { useAuthStore } from '@/features/auth/stores/auth-store';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

export function QRScannerPage() {
  const { loginViaQR, isLoading } = useAuthStore();
  const [error, setError] = useState<string | null>(null);
  const [scanned, setScanned] = useState(false);

  const handleScan = async (event: any) => {
    if (scanned || isLoading) return;

    const qrData = event?.nativeEvent?.codeStringValue ?? event?.codeStringValue;
    if (!qrData) return;

    // Basic format validation before sending
    if (!qrData.startsWith('baander://pair')) {
      setError('Invalid QR code. Scan a Baander pairing QR code.');
      return;
    }

    setScanned(true);
    setError(null);

    try {
      await loginViaQR(qrData);
    } catch (err: any) {
      setScanned(false);
      setError(err?.response?.data?.message ?? err?.message ?? 'QR login failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.instructions}>
        Point your camera at the Baander QR code on your server screen
      </Text>

      {isLoading || scanned ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color={colors.primary} />
          <Text style={styles.loadingText}>
            {scanned ? 'Connecting to server...' : 'Scanning...'}
          </Text>
        </View>
      ) : (
        <View style={styles.cameraContainer}>
          <CameraKitCameraScreen
            scanBarcode
            onReadCode={handleScan}
            showFrame
            laserColor={colors.primary}
            frameColor={colors.border}
            style={styles.camera}
          />
        </View>
      )}

      {error && <Text style={styles.error}>{error}</Text>}

      {scanned && !isLoading && (
        <Pressable style={styles.retryButton} onPress={() => setScanned(false)}>
          <Text style={styles.retryText}>Try again</Text>
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: spacing[3],
  },
  instructions: {
    color: colors.muted,
    fontSize: 13,
    textAlign: 'center',
    marginBottom: spacing[2],
  },
  cameraContainer: {
    height: 280,
    borderRadius: radii.lg,
    overflow: 'hidden',
    backgroundColor: colors.card,
  },
  camera: {
    flex: 1,
  },
  loadingContainer: {
    height: 280,
    borderRadius: radii.lg,
    backgroundColor: colors.card,
    justifyContent: 'center',
    alignItems: 'center',
    gap: spacing[3],
  },
  loadingText: {
    color: colors.muted,
    fontSize: 14,
  },
  error: {
    color: colors.destructive,
    fontSize: 13,
    textAlign: 'center',
  },
  retryButton: {
    backgroundColor: colors.secondary,
    borderRadius: radii.lg,
    paddingVertical: spacing[3],
    alignItems: 'center',
  },
  retryText: {
    color: colors.foreground,
    fontSize: 14,
    fontWeight: '500',
  },
});
