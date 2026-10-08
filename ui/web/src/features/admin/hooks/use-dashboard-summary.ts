import { useQuery } from '@tanstack/react-query'
import { getServerStats } from '../api/server-stats-api'
import { getStatusOverview } from '../api/job-monitor-api'

export interface DashboardSummary {
  uptime: string
  memoryUsage: string
  memoryPeak: string
  redisConnected: boolean
  totalJobs: number
  pendingJobs: number
  workers: string
}

export function useDashboardSummary() {
  const { data: stats } = useQuery({
    queryKey: ['server-stats'],
    queryFn: getServerStats,
    refetchInterval: 10_000,
    retry: false,
  })

  const { data: statusOverview } = useQuery({
    queryKey: ['status-overview'],
    queryFn: getStatusOverview,
    refetchInterval: 10_000,
    retry: false,
  })

  // Memory is summed over the web server's workers; uptime is the container's,
  // which every worker reports alike.
  const workers = stats?.workers ?? []
  const summary: DashboardSummary = {
    uptime: workers.length > 0 ? formatUptime(workers[0].process.uptime) : '—',
    memoryUsage: workers.length > 0 ? `${sumMegabytes(workers.map((worker) => worker.memory.usage))} MB` : '—',
    memoryPeak: workers.length > 0 ? `${sumMegabytes(workers.map((worker) => worker.memory.peak))} MB` : '—',
    redisConnected: stats?.redis?.connected ?? false,
    totalJobs: statusOverview
      ? Object.values(statusOverview.counts).reduce((a, b) => a + b, 0)
      : 0,
    pendingJobs:
      statusOverview?.counts?.pending ?? statusOverview?.counts?.new ?? 0,
    workers: stats ? `${workers.length} ${workers.length === 1 ? 'worker' : 'workers'}` : '—',
  }

  return { summary, stats, statusOverview }
}

function sumMegabytes(values: number[]): number {
  return Math.round(values.reduce((total, value) => total + value, 0) * 100) / 100
}

function formatUptime(seconds: number): string {
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (d > 0) return `${d}d ${h}h ${m}m`
  if (h > 0) return `${h}h ${m}m`
  return `${m}m`
}
