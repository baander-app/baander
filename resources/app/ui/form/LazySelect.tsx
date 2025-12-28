import { useState } from 'react';
import { Select } from '@radix-ui/themes';
import { SelectOption } from './types';

interface LazySelectProps {
  value: string;
  onChange: (value: string) => void;
  options: SelectOption[];
  placeholder?: string;
  disabled?: boolean;
}

/**
 * Select component with lazy-loaded options for improved performance
 * Options are only rendered when the dropdown is opened, not on mount
 *
 * This is especially useful for selects with many options (50+ items)
 *
 * @example
 * ```tsx
 * <LazySelect
 *   value={country}
 *   onChange={setCountry}
 *   options={countryOptions}
 *   placeholder="Select country"
 * />
 * ```
 */
export function LazySelect({
  value,
  onChange,
  options,
  placeholder,
  disabled
}: LazySelectProps) {
  const [opened, setOpened] = useState(false);

  return (
    <Select.Root
      value={value}
      onValueChange={onChange}
      disabled={disabled}
      onOpenChange={(open) => {
        if (open) setOpened(true);
      }}
    >
      <Select.Trigger placeholder={placeholder} />
      <Select.Content>
        {opened && options.map((opt) => (
          <Select.Item key={opt.value} value={opt.value}>
            {opt.label}
          </Select.Item>
        ))}
      </Select.Content>
    </Select.Root>
  );
}
