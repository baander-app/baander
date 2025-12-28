import { Flex } from '@radix-ui/themes';
import { MetricCard } from '@/app/modules/dashboard/queue-monitor/components/metric-card.tsx';
import { useQueueMetricsMetrics } from '@/app/libs/api-client/gen/endpoints/system/system.ts';

export function Metrics() {
  const { data } = useQueueMetricsMetrics();

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