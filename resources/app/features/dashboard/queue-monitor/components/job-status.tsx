import { Badge } from '@mantine/core';
import { statusToColor, statusToName } from '@/utils/job-status.ts';

export interface JobStatusProps {
  status: number;
}

export function JobStatus({ status }: JobStatusProps) {
  const statusName = statusToName(status);
  const color = statusToColor(status);

  return (
    <Badge color={color}>{statusName}</Badge>
  );
}