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
  pid: string
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

  const summary: DashboardSummary = {
    uptime: stats ? formatUptime(stats.process.uptime) : '—',
    memoryUsage: stats ? `${stats.memory.usage} MB` : '—',
    memoryPeak: stats ? `${stats.memory.peak} MB` : '—',
    redisConnected: stats?.redis?.connected ?? false,
    totalJobs: statusOverview
      ? Object.values(statusOverview.counts).reduce((a, b) => a + b, 0)
      : 0,
    pendingJobs:
      statusOverview?.counts?.pending ?? statusOverview?.counts?.new ?? 0,
    pid: stats ? String(stats.process.pid) : '—',
  }

  return { summary, stats, statusOverview }
}

function formatUptime(seconds: number): string {
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (d > 0) return `${d}d ${h}h ${m}m`
  if (h > 0) return `${h}h ${m}m`
  return `${m}m`
}
