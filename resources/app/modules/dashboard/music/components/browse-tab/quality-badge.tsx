import { Badge } from '@radix-ui/themes';

export interface QualityBadgeProps {
  score: number;
}

export function QualityBadge({ score }: QualityBadgeProps) {
  const percentage = Math.round(score * 100);

  // Color coding: green (>0.7), yellow (0.5-0.7), red (<0.5)
  const getColor = () => {
    if (score >= 0.7) return 'green';
    if (score >= 0.5) return 'yellow';
    return 'red';
  };

  return (
    <Badge color={getColor()}>
      {percentage}%
    </Badge>
  );
}
