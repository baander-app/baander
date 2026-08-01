/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        background: '#000000',
        card: '#0a0a0b',
        sidebar: '#080809',
        border: '#1a1a1f',
        foreground: '#ffffff',
        muted: '#8b8d97',
        primary: '#3b82f6',
        secondary: '#1e293b',
        accent: '#1e40af',
        destructive: '#ef4444',
      },
      fontFamily: {
        sans: ['Inter'],
        mono: ['JetBrains Mono'],
      },
    },
  },
  plugins: [],
};
