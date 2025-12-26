import { Box, Text } from '@radix-ui/themes';

interface FormSectionProps {
  title: string;
  children: React.ReactNode;
}

/**
 * FormSection component for grouping related form fields
 * Provides visual separation with section titles
 *
 * @example
 * ```tsx
 * <FormSection title="Basic Info">
 *   <FormField {...} />
 *   <FormField {...} />
 * </FormSection>
 * ```
 */
export function FormSection({ title, children }: FormSectionProps) {
  return (
    <Box mb="6" p="4" style={{ backgroundColor: 'var(--gray-3)', borderRadius: '8px' }}>
      <Text size="4" weight="bold" mb="3" as="div">
        {title}
      </Text>
      <Box>{children}</Box>
    </Box>
  );
}
