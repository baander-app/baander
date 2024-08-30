import dayjs from 'dayjs';

export interface DateTimeProps {
  time: string | Date;
  format?: string;
}

export function DateTime({time, format}: DateTimeProps) {
  const formatted = dayjs(time);

  return (
    <>{formatted.format(format)}</>
  );
}