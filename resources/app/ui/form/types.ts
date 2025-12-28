/**
 * Type definitions for reusable form components
 */

export type FieldType = 'text' | 'textarea' | 'select' | 'multiselect' | 'checkbox';

export interface SelectOption {
  value: string;
  label: string;
}

export interface MultiSelectOption {
  id: number;
  name: string;
}

export type FieldOptions = SelectOption[] | MultiSelectOption[];

export interface FormFieldConfig<T extends Record<string, any>> {
  name: keyof T;
  label: string;
  type: FieldType;
  placeholder?: string;
  inputType?: 'text' | 'number' | 'email';
  options?: FieldOptions;
  disabled?: boolean;
  description?: string;
}

export interface FormSectionConfig<T extends Record<string, any>> {
  title: string;
  fields: readonly (keyof T)[];
}
