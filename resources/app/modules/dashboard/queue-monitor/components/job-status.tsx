import { Badge } from '@radix-ui/themes';
import { statusToColor } from '@/app/utils/job-status.ts';

export interface JobStatusProps {
  status: string;
}

export function JobStatus({ status }: JobStatusProps) {
  const color = statusToColor(status);

  return (
    <Badge color={color}>{status}</Badge>
  );
}