import { useState } from 'react';
import { TextInput, TextInputProps } from '@mantine/core';
import styles from './floating-label-input.module.scss';

export interface FloatingLabelInputProps extends TextInputProps {
  label: string;
}

export function FloatingLabelInput({...props}: FloatingLabelInputProps) {
  const [focused, setFocused] = useState(false);
  const [value, setValue] = useState('');
  const floating = value.trim().length !== 0 || focused || undefined;

  return (
    <TextInput
      {...props}
      classNames={styles}
      value={value}
      onChange={(event) => setValue(event.currentTarget.value)}
      onFocus={() => setFocused(true)}
      onBlur={() => setFocused(false)}
      mt="md"
      autoComplete="nope"
      data-floating={floating}
      labelProps={{ 'data-floating': floating }}
      data-1p-ignore
    />
  );
}