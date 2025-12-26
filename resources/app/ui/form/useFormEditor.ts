import { useForm } from 'laravel-precognition-react';
import { useFieldLock } from './useFieldLock';

export interface UseFormEditorOptions<T extends Record<string, unknown>> {
  method: 'get' | 'post' | 'put' | 'patch' | 'delete';
  url: string | (() => string);
  initialData: T;
  initialLockedFields?: (keyof T)[];
  onSubmit?: (data: T & { lockedFields?: string[] }) => void | Promise<void>;
}

export interface UseFormEditorReturn<T extends Record<string, unknown>> {
  // Form state
  form: ReturnType<typeof useForm<T>>;

  // Lock state
  lockMode: boolean;
  setLockMode: (mode: boolean) => void;
  lockedFields: Set<keyof T>;
  toggleFieldLock: (field: keyof T) => void;
  isFieldLocked: (field: keyof T) => boolean;

  // Actions
  submit: () => Promise<void>;
}

/**
 * Hook for form editors with Precognition validation and field locking
 * Combines form state, lock management, and submission handling
 *
 * @example
 * ```tsx
 * const { form, lockMode, setLockMode, toggleFieldLock, isFieldLocked, submit }
 *   = useFormEditor({
 *     method: 'put',
 *     url: `/api/libraries/${librarySlug}/albums/${album?.publicId}`,
 *     initialData: {
 *       title: album?.title || '',
 *       type: album?.type || '',
 *       // ... other fields
 *     },
 *     initialLockedFields: album?.lockedFields,
 *     onSubmit: async (data) => {
 *       await updateAlbum(data);
 *       onClose();
 *     },
 *   });
 * ```
 */
export function useFormEditor<T extends Record<string, unknown>>({
  method,
  url,
  initialData,
  initialLockedFields,
  onSubmit,
}: UseFormEditorOptions<T>): UseFormEditorReturn<T> {
  // Field lock management
  const { lockMode, setLockMode, lockedFields, toggleFieldLock, isFieldLocked } =
    useFieldLock<keyof T>(initialLockedFields);

  // Initialize Precognition form
  const form = useForm<T>(method, url, initialData);

  // Submit handler that includes locked fields
  const submit = async () => {
    await form.submit();

    if (onSubmit) {
      const data = { ...form.data } as T;
      const dataWithLocks = {
        ...data,
        lockedFields: Array.from(lockedFields) as string[],
      };
      await onSubmit(dataWithLocks);
    }
  };

  return {
    form,
    lockMode,
    setLockMode,
    lockedFields,
    toggleFieldLock,
    isFieldLocked,
    submit,
  };
}
