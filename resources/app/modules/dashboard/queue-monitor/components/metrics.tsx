import { useQueueServiceGetApiQueueMetricsMetrics } from '@/api-client/queries';
import { Flex } from '@radix-ui/themes';
import { MetricCard } from '@/modules/dashboard/queue-monitor/components/metric-card.tsx';

export function Metrics() {
  const { data } = useQueueServiceGetApiQueueMetricsMetrics();

  return (
    <Flex gap="3" mt="4" justify="center">
      {data?.map((metric, index) => (
        <MetricCard
          key={index}
          title={metric.title}
          formattedValue={metric.formattedValue}
          formattedPreviousValue={metric.formattedPreviousValue}
        />
      ))}
    </Flex>
  )
}