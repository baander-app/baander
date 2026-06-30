import '@testing-library/jest-dom/vitest'

// Polyfill ResizeObserver for Radix UI components (Slider, etc.)
if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof globalThis.ResizeObserver
}

// Mock localStorage for zustand persist middleware (not available in jsdom by default)
const localStorageStore: Record<string, string> = {}

if (typeof globalThis.localStorage === 'undefined' || !globalThis.localStorage.setItem) {
  globalThis.localStorage = {
    getItem: (key: string) => localStorageStore[key] ?? null,
    setItem: (key: string, value: string) => { localStorageStore[key] = value },
    removeItem: (key: string) => { delete localStorageStore[key] },
    clear: () => { Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k]) },
    get length() { return Object.keys(localStorageStore).length },
    key: (i: number) => Object.keys(localStorageStore)[i] ?? null,
  } as Storage
}
