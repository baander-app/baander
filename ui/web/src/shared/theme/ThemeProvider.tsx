import React, { useMemo, useState, useEffect } from 'react';
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components';
import type { Theme, ThemeMood, AccentColor } from './theme.types';
import { resolveTheme } from './resolve-theme';
import { GlobalStyles } from './GlobalStyles';

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [mood, setMood] = useState<ThemeMood>(readMoodFromDOM);
  const [accent, setAccent] = useState<AccentColor>(readAccentFromDOM);

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setMood(readMoodFromDOM());
      setAccent(readAccentFromDOM());
    });

    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-theme', 'data-accent'],
    });

    return () => observer.disconnect();
  }, []);

  const theme = useMemo(() => resolveTheme(mood, accent), [mood, accent]);

  return (
    <SCTypedThemeProvider theme={theme}>
      <GlobalStyles />
      {children}
    </SCTypedThemeProvider>
  );
}

function readMoodFromDOM(): ThemeMood {
  return (document.documentElement.getAttribute('data-theme') as ThemeMood) ?? 'dark';
}

function readAccentFromDOM(): AccentColor {
  return (document.documentElement.getAttribute('data-accent') as AccentColor) ?? 'violet';
}
