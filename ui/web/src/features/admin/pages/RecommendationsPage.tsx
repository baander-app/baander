import styled from 'styled-components'
import { Sparkles, Play, Loader2 } from 'lucide-react'
import { AdminPageHeader } from '../components/layout/AdminPageHeader'
import { useState } from 'react'
import {
  useRecommendationCoverage,
  useSourceQuality,
  useRecommendationFreshness,
  useRecommendationJobs,
  useGenerateRecommendations,
  useCancelRecommendationJob,
  useRequeueRecommendationJob,
} from '../hooks/use-recommendations-admin'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { ProgressBar } from '@/shared/components/progress-bar'
import { ActiveJobCard } from '../components/monitor/ActiveJobCard'
import { RecentJobList } from '../components/monitor/RecentJobList'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const SectionTitle = styled.h2`
  margin-bottom: 0.75rem;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const CoverageLayout = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const CoverageHeader = styled.div`
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
`

const BigValue = styled.span`
  font-size: 1.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
`

const CoverageSubtext = styled.span`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const StatsRow = styled.div`
  display: flex;
  gap: 1.5rem;
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const StatHighlight = styled.span`
  font-variant-numeric: tabular-nums;
  font-weight: 500;
  color: var(--color-foreground);
`

const StyledTable = styled.table`
  width: 100%;
  font-size: 0.8125rem;
`

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Th = styled.th`
  padding-bottom: 0.5rem;
`

const ThRight = styled.th`
  padding-bottom: 0.5rem;
  text-align: right;
`

const BodyRow = styled.tr`
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  transition: background-color var(--duration-hover) ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const TdBold = styled.td`
  padding: 0.375rem 0;
  font-weight: 500;
`

const TdRight = styled.td`
  padding: 0.375rem 0;
  text-align: right;
  font-variant-numeric: tabular-nums;
`

const QualityInfo = styled.div`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const FreshnessRow = styled.div`
  font-size: 0.8125rem;
`

const FreshnessLabel = styled.span`
  color: var(--color-muted-foreground);
`

const FreshnessValue = styled.span`
  font-variant-numeric: tabular-nums;
  font-weight: 500;
`

const EmptyText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const SkeletonStack = styled.div<{ $gap?: string }>`
  display: flex;
  flex-direction: column;
  gap: ${({ $gap }) => $gap ?? '0.25rem'};
`

export function RecommendationsPage() {
  const { data: coverage, isLoading: coverageLoading } = useRecommendationCoverage()
  const { data: quality, isLoading: qualityLoading } = useSourceQuality()
  const { data: freshness, isLoading: freshnessLoading } = useRecommendationFreshness()
  const { data: jobs, isLoading: jobsLoading } = useRecommendationJobs(5)

  const generateMutation = useGenerateRecommendations()
  const cancelMutation = useCancelRecommendationJob()
  const requeueMutation = useRequeueRecommendationJob()

  const [mode, setMode] = useState<'full' | 'incremental'>('incremental')

  const handleGenerate = async () => {
    try {
      await generateMutation.mutateAsync({ mode })
    } catch (error) {
      console.error('Failed to generate recommendations:', error)
    }
  }

  const handleCancel = async (publicId: string) => {
    try {
      await cancelMutation.mutateAsync(publicId)
    } catch (error) {
      console.error('Failed to cancel job:', error)
    }
  }

  const handleRequeue = async (publicId: string) => {
    try {
      await requeueMutation.mutateAsync(publicId)
    } catch (error) {
      console.error('Failed to requeue job:', error)
    }
  }

  const activeJob = jobs?.find((j) => j.status === 'pending' || j.status === 'in_progress')
  const isGenerating = generateMutation.isPending || !!activeJob

  return (
    <Container>
      <AdminPageHeader
        title="Recommendations"
        subtitle="Monitor recommendation coverage and trigger generation"
        icon={Sparkles}
        action={
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <Select value={mode} onValueChange={(v: 'full' | 'incremental') => setMode(v)} disabled={isGenerating}>
              <SelectTrigger style={{ width: '140px' }}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="incremental">Incremental</SelectItem>
                <SelectItem value="full">Full</SelectItem>
              </SelectContent>
            </Select>
            <Button onClick={handleGenerate} disabled={isGenerating} size="sm">
              {isGenerating ? (
                <Loader2 size={14} style={{ animation: 'spin 1s linear infinite' }} />
            ) : (
              <Play size={14} />
            )}
            {isGenerating ? 'Running...' : 'Generate'}
          </Button>
        </div>
        }
      />

      {/* Active Job */}
      {activeJob && (
        <ActiveJobCard
          job={activeJob}
          onCancel={handleCancel}
          isCancelling={cancelMutation.isPending}
        />
      )}

      {/* Coverage */}
      <section>
        <SectionTitle>Coverage</SectionTitle>
        {coverageLoading ? (
          <SkeletonStack $gap="0.5rem">
            <Skeleton style={{ height: '1rem', width: '100%' }} />
            <Skeleton style={{ height: '1rem', width: '12rem' }} />
          </SkeletonStack>
        ) : coverage ? (
          <CoverageLayout>
            <CoverageHeader>
              <BigValue>
                {coverage.coverage_percentage.toFixed(1)}%
              </BigValue>
              <CoverageSubtext>of tracks have recommendations</CoverageSubtext>
            </CoverageHeader>
            <ProgressBar value={Math.min(100, coverage.coverage_percentage)} />
            <StatsRow>
              <span>
                <StatHighlight>
                  {coverage.tracks_with_recommendations.toLocaleString()}
                </StatHighlight>{' '}
                covered
              </span>
              <span>
                <StatHighlight>
                  {coverage.tracks_without_recommendations.toLocaleString()}
                </StatHighlight>{' '}
                uncovered
              </span>
              <span>
                <StatHighlight>
                  {coverage.total_tracks.toLocaleString()}
                </StatHighlight>{' '}
                total
              </span>
            </StatsRow>
          </CoverageLayout>
        ) : null}
      </section>

      {/* Source Quality */}
      <section>
        <SectionTitle>Source Quality</SectionTitle>
        {qualityLoading ? (
          <SkeletonStack $gap="0.25rem">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} style={{ height: '2rem', width: '100%' }} />
            ))}
          </SkeletonStack>
        ) : quality ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            <QualityInfo>
              Avg confidence:{' '}
              <StatHighlight>
                {(quality.avg_confidence_score * 100).toFixed(1)}%
              </StatHighlight>
            </QualityInfo>
            {Object.entries(quality.by_source_type).length > 0 ? (
              <StyledTable>
                <thead>
                  <HeadRow>
                    <Th>Source Type</Th>
                    <ThRight>Count</ThRight>
                  </HeadRow>
                </thead>
                <tbody>
                  {Object.entries(quality.by_source_type).map(([type, count]) => (
                    <BodyRow key={type}>
                      <TdBold>{type}</TdBold>
                      <TdRight>{(count as number).toLocaleString()}</TdRight>
                    </BodyRow>
                  ))}
                </tbody>
              </StyledTable>
            ) : (
              <EmptyText>No recommendation sources.</EmptyText>
            )}
          </div>
        ) : null}
      </section>

      {/* Freshness */}
      <section>
        <SectionTitle>Freshness</SectionTitle>
        {freshnessLoading ? (
          <SkeletonStack $gap="0.5rem">
            <Skeleton style={{ height: '1rem', width: '12rem' }} />
            <Skeleton style={{ height: '1rem', width: '16rem' }} />
          </SkeletonStack>
        ) : freshness ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            <FreshnessRow>
              <FreshnessLabel>Average age: </FreshnessLabel>
              <FreshnessValue>{formatAge(freshness.avg_age_seconds)}</FreshnessValue>
            </FreshnessRow>
            <FreshnessRow>
              <FreshnessLabel>Last generated: </FreshnessLabel>
              <FreshnessValue>
                {freshness.last_generated_at
                  ? new Date(freshness.last_generated_at).toLocaleString()
                  : 'Never'}
              </FreshnessValue>
            </FreshnessRow>
          </div>
        ) : null}
      </section>

      {/* Recent Jobs */}
      <section>
        <SectionTitle>Recent Jobs</SectionTitle>
        {jobsLoading ? (
          <SkeletonStack $gap="0.5rem">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} style={{ height: '2.5rem', width: '100%' }} />
            ))}
          </SkeletonStack>
        ) : jobs && jobs.length > 0 ? (
          <RecentJobList
            jobs={jobs}
            onRequeue={handleRequeue}
            isRequeuing={requeueMutation.isPending}
          />
        ) : (
          <EmptyText>No jobs yet.</EmptyText>
        )}
      </section>
    </Container>
  )
}

function formatAge(seconds: number): string {
  if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes`
  if (seconds < 86400) return `${(seconds / 3600).toFixed(1)} hours`
  return `${(seconds / 86400).toFixed(1)} days`
}
