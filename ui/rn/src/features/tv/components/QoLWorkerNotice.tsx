import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';
import type { QoLWorkerError } from '@/features/admin/qol-report';

interface QoLWorkerNoticeProps {
  missingWorkers: number[];
  workerErrors: QoLWorkerError[];
}

/**
 * Names every web server worker that did not answer or answered with an error,
 * so a partial QoL snapshot never looks complete.
 */
export function QoLWorkerNotice({ missingWorkers, workerErrors }: QoLWorkerNoticeProps) {
  if (missingWorkers.length === 0 && workerErrors.length === 0) return null;

  return (
    <View style={styles.notice}>
      <Text style={styles.title}>Some workers are missing from this view</Text>
      {missingWorkers.map((workerId) => (
        <Text key={`missing-${workerId}`} style={styles.line}>Worker {workerId} did not answer in time.</Text>
      ))}
      {workerErrors.map(({ worker_id: workerId, error }) => (
        <Text key={`error-${workerId}-${error}`} style={styles.line}>Worker {workerId} failed: {error}</Text>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  notice: {
    borderWidth: 1,
    borderColor: tvColors.destructive,
    borderRadius: 12,
    padding: tvSpacing.gap_md,
  },
  title: {
    fontSize: tvFontSizes.body,
    fontWeight: '600',
    color: tvColors.destructive,
    marginBottom: tvSpacing.gap_xs,
  },
  line: {
    fontSize: tvFontSizes.sm,
    color: tvColors.destructive,
  },
});
