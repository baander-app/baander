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
 *
 * With Precognition configured to validate-only (determineSuccessUsing(() => false)),
 * this hook validates the form and then calls onSubmit for actual submission via React Query.
 *
 * @example
 * ```tsx
 * const { form, lockMode, setLockMode, toggleFieldLock, isFieldLocked, submit }
 *   = useFormEditor({
 *     method: 'put',
 *     url: `/api/libraries/${librarySlug}/albums/${album?.publicId}`,
 *     initialData: { title: album?.title || '', ... },
 *     initialLockedFields: album?.lockedFields,
 *     onSubmit: async (data) => {
 *       // This will be called AFTER validation passes
 *       // Use React Query mutation here
 *       await updateAlbum.mutateAsync(data);
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

  // Submit handler that validates via Precognition, then calls onSubmit callback
  const submit = async () => {
    // Trigger Precognition validation (won't actually submit due to determineSuccessUsing)
    await form.submit();

    // Check if validation passed (no errors)
    if (!form.hasErrors) {
      if (onSubmit) {
        const data = { ...form.data } as T;
        const dataWithLocks = {
          ...data,
          lockedFields: Array.from(lockedFields) as string[],
        };
        await onSubmit(dataWithLocks);
      }
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
