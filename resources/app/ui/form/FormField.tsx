import { TextField, Select, Text, Box, Button, Flex, TextArea, Checkbox } from '@radix-ui/themes';
import { LockClosedIcon, LockOpen1Icon } from '@radix-ui/react-icons';
import { FormFieldConfig, SelectOption, MultiSelectOption } from './types';
import { MultiSelect } from './MultiSelect';
import { useState } from 'react';

interface FormFieldProps<TFormValues extends Record<string, any>> {
  config: FormFieldConfig<TFormValues>;
  value: any;
  onChange: (value: any) => void;
  errors: any;
  lockMode: boolean;
  isFieldLocked: (field: keyof TFormValues) => boolean;
  onToggleLock: (field: keyof TFormValues) => void;
}

// Type guards
function isSelectOption(options: FormFieldConfig<any>['options']): options is SelectOption[] {
  return Array.isArray(options) && options.length > 0 && 'value' in options[0];
}

function isMultiSelectOption(options: FormFieldConfig<any>['options']): options is MultiSelectOption[] {
  return Array.isArray(options) && options.length > 0 && 'id' in options[0];
}

/**
 * Unified form field component supporting text, textarea, select, and multiselect
 * Works with laravel-precognition-react and supports field locking
 *
 * @example
 * ```tsx
 * <FormField
 *   config={{ name: 'title', label: 'Title', type: 'text' }}
 *   value={form.title}
 *   onChange={(value) => form.setTitle(value)}
 *   errors={form.errors}
 *   lockMode={lockMode}
 *   isFieldLocked={(f) => lockedFields.has(f)}
 *   onToggleLock={toggleLock}
 * />
 * ```
 */
export function FormField<TFormValues extends Record<string, any>>({
  config,
  value,
  onChange,
  errors,
  lockMode,
  isFieldLocked,
  onToggleLock
}: FormFieldProps<TFormValues>) {
  const { name, label, type, placeholder, inputType, options, disabled, description } = config;
  const fieldName = name;
  const locked = lockMode && isFieldLocked(fieldName);
  const fieldError = errors[String(name)];

  // Lazy load options for select fields to improve performance
  const [selectOpened, setSelectOpened] = useState(false);

  const renderLockButton = () =>
    lockMode ? (
      <Button
        size="1"
        variant="ghost"
        onClick={() => onToggleLock(fieldName)}
        className="lock-button"
        title={locked ? 'Unlock field' : 'Lock field'}
      >
        {locked ? <LockClosedIcon /> : <LockOpen1Icon />}
      </Button>
    ) : null;

  const renderError = () =>
    fieldError ? (
      <Text color="red" size="1" mt="1">
        {fieldError.message}
      </Text>
    ) : null;

  const renderDescription = () =>
    description && type !== 'checkbox' ? (
      <Text size="1" color="gray" mb="1">
        {description}
      </Text>
    ) : null;

  return (
    <Box className={`form-field ${locked ? 'locked' : ''}`}>
      <Flex align="center" gap="2" mb="1">
        <Text as="label" size="2" weight="medium">
          {label}
        </Text>
        {renderLockButton()}
      </Flex>

      {renderDescription()}

      {type === 'text' && (
        <TextField.Root
          value={value ?? ''}
          onChange={(e) => {
            const newValue = inputType === 'number'
              ? (e.target.value === '' ? undefined : Number(e.target.value))
              : e.target.value;
            onChange(newValue);
          }}
          placeholder={placeholder}
          disabled={locked || disabled}
          type={inputType || 'text'}
        />
      )}

      {type === 'textarea' && (
        <TextArea
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          disabled={locked || disabled}
          rows={4}
        />
      )}

      {type === 'checkbox' && (
        <Flex align="center" gap="2">
          <Checkbox
            checked={value ?? false}
            onCheckedChange={(checked) => {
              onChange(checked === true ? true : false);
            }}
            disabled={locked || disabled}
          />
          <Text size="2" color="gray">
            {description}
          </Text>
        </Flex>
      )}

      {type === 'select' && options && isSelectOption(options) && (
        <Select.Root
          value={value ?? ''}
          onValueChange={onChange}
          disabled={locked || disabled}
          onOpenChange={(open) => {
            if (open) setSelectOpened(true);
          }}
        >
          <Select.Trigger placeholder={placeholder ?? ''} />
          <Select.Content>
            {selectOpened && options.map((opt) => (
              <Select.Item key={opt.value} value={opt.value}>
                {opt.label}
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      )}

      {type === 'multiselect' && options && isMultiSelectOption(options) && (
        <MultiSelect
          placeholder={placeholder || `Select ${label.toLowerCase()}`}
          value={value ?? []}
          onChange={onChange}
          options={options}
          disabled={locked || disabled}
        />
      )}

      {renderError()}
    </Box>
  );
}
