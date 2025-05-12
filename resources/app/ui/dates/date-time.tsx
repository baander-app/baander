import dayjs from 'dayjs';

type FormatStyle = 'short' | 'medium' | 'long';

export interface DateTimeProps {
  date: string | Date;
  format?: FormatStyle;
  locale?: string; // Optional locale override (e.g., 'en-US', 'fr-FR')
}


const getOptionsForFormat = (format: FormatStyle): Intl.DateTimeFormatOptions => {
  switch (format) {
    case 'short':
      return { dateStyle: 'short', timeStyle: 'short' };
    case 'medium':
      return { dateStyle: 'medium', timeStyle: 'short' };
    case 'long':
      return { dateStyle: 'long', timeStyle: 'short' };
    default:
      return { dateStyle: 'medium', timeStyle: 'short' };
  }
};

export function DateTime({date, format = 'medium', locale = navigator.language}: DateTimeProps) {
  const parsedDate = dayjs(date).toDate();
  const formatter = new Intl.DateTimeFormat(locale, getOptionsForFormat(format));
  const formatted = formatter.format(parsedDate);

  return <>{formatted}</>;
}