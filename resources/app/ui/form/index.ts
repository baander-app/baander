/**
 * Form components - barrel export
 *
 * @example
 * ```tsx
 * import { FormField, FormSection, MultiSelect, LazySelect, useFieldLock, useFormEditor } from '@/app/ui/form';
 * import type { FormFieldConfig, FormSectionConfig } from '@/app/ui/form';
 * ```
 */

export { FormField } from './FormField';
export { FormSection } from './FormSection';
export { MultiSelect } from './MultiSelect';
export { LazySelect } from './LazySelect';
export { useFieldLock } from './useFieldLock';
export { useFormEditor } from './useFormEditor';

export type { FormFieldConfig, FormSectionConfig, FieldType, SelectOption, MultiSelectOption } from './types';
