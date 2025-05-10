import { Card, Text } from '@radix-ui/themes';

export interface MetricCardProps {
  title: string;
  formattedValue: string;
  formattedPreviousValue: string | null;
}

export function MetricCard({ title, formattedValue, formattedPreviousValue }: MetricCardProps) {
  return (
    <Card>
      <Text mr="2">{title}</Text>

      <Text>{formattedValue}</Text>

      {formattedPreviousValue && (
        <Text>{formattedPreviousValue}</Text>
      )}
    </Card>
  );
}