/**
 * useTVFocus - Hook for managing focus state in TV components.
 *
 * Provides:
 * - Focus state tracking
 * - onFocus/onBlur callbacks
 * - Focus restoration helpers
 *
 * Use this in TV components to track which element has focus
 * and restore focus when navigating back to a screen.
 */

import { useState, useRef, useCallback } from 'react';

export interface FocusState {
  isFocused: boolean;
  handleFocus: () => void;
  handleBlur: () => void;
}

export function useTVFocus(initialState = false): FocusState {
  const [isFocused, setIsFocused] = useState(initialState);

  const handleFocus = useCallback(() => {
    setIsFocused(true);
  }, []);

  const handleBlur = useCallback(() => {
    setIsFocused(false);
  }, []);

  return {
    isFocused,
    handleFocus,
    handleBlur,
  };
}

/**
 * Tracks the last focused item in a screen for restoration on back navigation.
 *
 * Call saveFocus(id) when an item receives focus.
 * Call restoreFocus() when returning to a screen to focus the last item.
 */
export function useTVFocusRestoration<T extends string>() {
  const lastFocusedRef = useRef<T | null>(null);

  const saveFocus = useCallback((id: T) => {
    lastFocusedRef.current = id;
  }, []);

  const restoreFocus = useCallback((): T | null => {
    return lastFocusedRef.current;
  }, []);

  const clearFocus = useCallback(() => {
    lastFocusedRef.current = null;
  }, []);

  return {
    saveFocus,
    restoreFocus,
    clearFocus,
    lastFocused: lastFocusedRef.current,
  };
}
