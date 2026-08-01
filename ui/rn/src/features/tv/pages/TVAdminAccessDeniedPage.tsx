/**
 * TVAdminAccessDeniedPage -- dedicated access denied screen.
 *
 * Per user decision: dedicated screen before redirect (not silent redirect).
 * Explains the restriction, then redirects to home after delay.
 */

import React, { useEffect } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { TVFocusable } from '../components/TVFocusable';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export interface TVAdminAccessDeniedPageProps {
  reason?: 'not_authenticated' | 'not_admin';
}

export function TVAdminAccessDeniedPage({ reason = 'not_admin' }: TVAdminAccessDeniedPageProps) {
  const navigation = useNavigation();

  useEffect(() => {
    // Redirect to home after 3 seconds
    const timer = setTimeout(() => {
      navigation.navigate('TVHome' as never);
    }, 3000);

    return () => clearTimeout(timer);
  }, [navigation]);

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Access Denied</Text>

      {reason === 'not_authenticated' ? (
        <Text style={styles.message}>
          You need to log in to access admin features.
        </Text>
      ) : (
        <Text style={styles.message}>
          Your account does not have permission to access admin features.
        </Text>
      )}

      <Text style={styles.subtext}>Redirecting to home...</Text>

      <TVFocusable onPress={() => navigation.navigate('TVHome' as never)} style={styles.button}>
        <Text style={styles.buttonText}>Go to Home Now</Text>
      </TVFocusable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: tvColors.background,
    justifyContent: 'center',
    alignItems: 'center',
    padding: tvSpacing.sectionPaddingLarge,
    gap: tvSpacing.gap_lg,
  },
  title: {
    fontSize: tvFontSizes['3xl'],
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
  message: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
    textAlign: 'center',
  },
  subtext: {
    fontSize: tvFontSizes.sm,
    color: tvColors.textMuted,
  },
  button: {
    paddingHorizontal: tvSpacing.gap_xl,
    paddingVertical: tvSpacing.gap_md,
    backgroundColor: tvColors.primary,
    borderRadius: 8,
    marginTop: tvSpacing.gap_md,
  },
  buttonText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
});
