/**
 * TVAdminDashboardPage -- admin dashboard with server stats.
 *
 * Displays server health stats, active operations, quick actions.
 * Data refreshed via react-query polling.
 */

import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { TVAdminShell } from '../components/TVAdminShell';
import { TVStatGrid } from '../components/TVStatGrid';
import { TVJobList } from '../components/TVJobList';
import { TVFocusable } from '../components/TVFocusable';
import { useAdminStats } from '@/features/admin/hooks/useAdminStats';
import { useActiveJobs } from '@/features/admin/hooks/useActiveJobs';
import { tvColors, tvFontSizes, tvSpacing } from '../theme/tv-tokens';

export function TVAdminDashboardPage() {
  const { data: stats, isLoading: statsLoading } = useAdminStats();
  const { data: jobs } = useActiveJobs();

  return (
    <TVAdminShell
      title="Dashboard"
      breadcrumb="Admin / Overview"
      actions={null}
    >
      {statsLoading ? (
        <Text style={styles.loadingText}>Loading stats...</Text>
      ) : (
        <ScrollView style={styles.content} showsVerticalScrollIndicator={false}>
          {/* Stats grid */}
          <TVStatGrid stats={stats} />

          {/* Active jobs */}
          <Text style={styles.sectionTitle}>Active Jobs</Text>
          <TVJobList jobs={jobs} />

          {/* Quick actions */}
          <Text style={styles.sectionTitle}>Quick Actions</Text>
          <View style={styles.actions}>
            <TVFocusable
              onPress={() => {}}
              style={styles.actionButton}
            >
              <Text style={styles.actionText}>Scan Library</Text>
            </TVFocusable>
            <TVFocusable
              onPress={() => {}}
              style={styles.actionButton}
            >
              <Text style={styles.actionText}>Clear Cache</Text>
            </TVFocusable>
            <TVFocusable
              onPress={() => {}}
              style={styles.actionButton}
            >
              <Text style={styles.actionText}>Restart Jobs</Text>
            </TVFocusable>
          </View>
        </ScrollView>
      )}
    </TVAdminShell>
  );
}

const styles = StyleSheet.create({
  content: {
    flex: 1,
  },
  loadingText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textMuted,
    textAlign: 'center',
    padding: tvSpacing.sectionPadding,
  },
  sectionTitle: {
    fontSize: tvFontSizes.xl,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
    marginTop: tvSpacing.sectionPaddingLarge,
    marginBottom: tvSpacing.gap_md,
  },
  actions: {
    flexDirection: 'row',
    gap: tvSpacing.gap_md,
    marginBottom: tvSpacing.sectionPaddingLarge,
  },
  actionButton: {
    paddingHorizontal: tvSpacing.gap_xl,
    paddingVertical: tvSpacing.gap_md,
    backgroundColor: tvColors.primary,
    borderRadius: 8,
  },
  actionText: {
    fontSize: tvFontSizes.body,
    color: tvColors.textPrimary,
    fontWeight: 'bold',
  },
});
