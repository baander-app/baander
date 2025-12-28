/**
 * Gender options for dropdown selects
 */
export const GENDER_OPTIONS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'non_binary', label: 'Non-binary' },
  { value: 'other', label: 'Other' },
  { value: 'unknown', label: 'Unknown' },
] as const;

export type GenderOption = typeof GENDER_OPTIONS[number];
