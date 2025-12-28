import { useState, useEffect } from 'react';

/**
 * Hook for managing field lock state in form editors
 * Prevents metadata sync from overwriting locked fields
 *
 * @param initialLockedFields - Array of field names that should be initially locked
 * @returns Object with lock state and helper functions
 *
 * @example
 * ```tsx
 * const { lockMode, lockedFields, toggleFieldLock, isFieldLocked, setLockedFields }
 *   = useFieldLock(album?.lockedFields);
 * ```
 */
export function useFieldLock<T extends string | number | symbol>(
  initialLockedFields?: T[]
) {
  const [lockMode, setLockMode] = useState(false);
  const [lockedFields, setLockedFields] = useState<Set<T>>(new Set(initialLockedFields || []));

  // Initialize locked fields from source
  useEffect(() => {
    if (initialLockedFields) {
      setLockedFields(new Set(initialLockedFields));
    }
  }, [initialLockedFields?.length]);

  const toggleFieldLock = (fieldName: T) => {
    const newLockedFields = new Set(lockedFields);
    if (newLockedFields.has(fieldName)) {
      newLockedFields.delete(fieldName);
    } else {
      newLockedFields.add(fieldName);
    }
    setLockedFields(newLockedFields);
  };

  const isFieldLocked = (fieldName: T): boolean => {
    return lockedFields.has(fieldName);
  };

  return {
    lockMode,
    setLockMode,
    lockedFields,
    setLockedFields,
    toggleFieldLock,
    isFieldLocked,
  };
}
