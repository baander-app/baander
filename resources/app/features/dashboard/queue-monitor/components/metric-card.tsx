import { Card, Text } from '@mantine/core';

export interface MetricCardProps {
  title: string;
  formattedValue: string;
  formattedPreviousValue: string | null;
}

export function MetricCard({ title, formattedValue, formattedPreviousValue }: MetricCardProps) {
  return (
    <Card
      shadow="sm"
      padding="lg"
      radius="md"
      withBorder
    >
      <Text fw="bold">{title}</Text>

      <Text>{formattedValue}</Text>

      {formattedPreviousValue && (
        <Text>{formattedPreviousValue}</Text>
      )}
    </Card>
  );
}