import React, { createContext, ReactNode, useCallback, useContext, useState } from 'react';
import dayjs from 'dayjs';

const localeFormatMap: Record<string, string> = {
  'en': 'MM-DD-YYYY HH:mm',        // English (US)
  'en-gb': 'DD-MM-YYYY HH:mm',     // English (UK)
  'de': 'DD.MM.YYYY HH:mm',        // German
  'fr': 'DD/MM/YYYY HH:mm',        // French
  'es': 'DD/MM/YYYY HH:mm',        // Spanish
  'th': 'DD/MM/YYYY HH:mm',        // Thai
  'zh-cn': 'YYYY-MM-DD HH:mm',     // Chinese (Simplified)
  // Add more locales and their formats as needed
};

const getLocalizedFormat = (locale: string): string => {
  return localeFormatMap[locale] || 'YYYY-MM-DD HH:mm'; // Default to ISO format
};

interface DateFormatterContextProps {
  locale: string;
  setLocale: (locale: string) => void;
  formatDate: (date: dayjs.Dayjs) => string;
}

const DateFormatterContext = createContext<DateFormatterContextProps | undefined>(undefined);
DateFormatterContext.displayName = 'DateFormatterContext';

const DateFormatterProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [locale, _setLocale] = useState<string>('en');

  const setLocale = useCallback((value: string) => {
    if (value && value !== locale && Object.keys(localeFormatMap).includes(value)) {
      dayjs.locale(value);
      _setLocale(value);
    }
  }, [locale, localeFormatMap]);

  const formatDate = (date: dayjs.Dayjs): string => {
    const formatString = getLocalizedFormat(locale);

    return dayjs(date).format(formatString);
  };

  return (
    <DateFormatterContext.Provider value={{ formatDate, locale, setLocale }}>
      {children}
    </DateFormatterContext.Provider>
  );
};

const useDateFormatter = (): DateFormatterContextProps => {
  const context = useContext(DateFormatterContext);
  if (!context) {
    throw new Error('useDateFormatter must be used within a DateFormatterProvider');
  }
  return context;
};

export { DateFormatterProvider, useDateFormatter };