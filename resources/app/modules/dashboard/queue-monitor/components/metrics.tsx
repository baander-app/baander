import { useQueueServiceQueueMetricsMetrics } from '@/api-client/queries';
import { Flex } from '@mantine/core';
import { MetricCard } from '@/modules/dashboard/queue-monitor/components/metric-card.tsx';

export function Metrics() {
  const { data } = useQueueServiceQueueMetricsMetrics();

  return (
    <Flex gap="sm" mt="sm" justify="center">
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