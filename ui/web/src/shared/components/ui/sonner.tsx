import { Toaster as Sonner } from 'sonner';

export function Toaster() {
  return (
    <Sonner
      position="bottom-right"
      toastOptions={{
        style: {
          background: 'var(--color-card)',
          color: 'var(--color-card-foreground)',
          border: '1px solid var(--color-border)',
          boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1)',
        },
      }}
      style={{
        '--normal-bg': 'var(--color-card)',
        '--normal-text': 'var(--color-card-foreground)',
        '--normal-border': 'var(--color-border)',
      } as React.CSSProperties}
    />
  );
}
