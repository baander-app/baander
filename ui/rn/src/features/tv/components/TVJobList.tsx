/**
 * TVJobList -- list of active background jobs for admin dashboard.
 *
 * Displays job status and progress.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { TVFocusable } from './TVFocusable';
import { tvColors, tvFontSizes, tvSizes, tvSpacing } from '../theme/tv-tokens';
import type { Job } from '@/features/admin/hooks/useActiveJobs';

export interface TVJobListProps {
  jobs: Job[];
}

export function TVJobList({ jobs }: TVJobListProps) {
  if (jobs.length === 0) {
    return (
      <View style={styles.empty}>
        <Text style={styles.emptyText}>No active jobs</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {jobs.map((job) => (
        <TVFocusable key={job.id} style={styles.job}>
          <View style={styles.jobContent}>
            <Text style={styles.jobType}>{job.type}</Text>
            <Text style={styles.jobProgress}>{job.progress}% complete</Text>
          </View>
          <View style={styles.progressBar}>
            <View
              style={[
                styles.progressFill,
                { width: `${job.progress}%` },
                job.status === 'failed' && styles.progressFailed,
              ]}
            />
          </View>
        </TVFocusable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: tvSpacing.gap_sm,
  },
  empty: {
    padding: tvSpacing.sectionPadding,
    alignItems: 'center',
  },
  emptyText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
  },
  job: {
    padding: tvSpacing.gap_md,
    backgroundColor: tvColors.card,
    borderRadius: 8,
    gap: tvSpacing.gap_sm,
  },
  jobContent: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  jobType: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
  },
  jobProgress: {
    fontSize: tvFontSizes.body,
    color: tvColors.textSecondary,
  },
  progressBar: {
    height: 4,
    backgroundColor: tvColors.border,
    borderRadius: 2,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: tvColors.primary,
  },
  progressFailed: {
    backgroundColor: tvColors.destructive,
  },
});
