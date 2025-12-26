import { useState, useEffect } from 'react';
import { Box, Select, Badge, Button, Flex } from '@radix-ui/themes';

interface MultiSelectProps {
  placeholder: string;
  value: string[];
  onChange: (value: string[]) => void;
  options: { id: number; name: string }[];
  disabled?: boolean;
}

/**
 * MultiSelect component for selecting multiple items with badge display
 * Reusable across all form editors (album, song, artist, etc.)
 */
export function MultiSelect({ placeholder, value, onChange, options, disabled }: MultiSelectProps) {
  const [selectedItems, setSelectedItems] = useState<{ id: number; name: string }[]>([]);

  // Initialize selected items from value prop
  useEffect(() => {
    const selected = options.filter(item => value.includes(item.name));
    setSelectedItems(selected);
  }, [value, options]);

  const handleSelectChange = (selectedValue: string) => {
    const selectedOption = options.find(option => option.name === selectedValue);
    if (selectedOption && !selectedItems.some(item => item.id === selectedOption.id)) {
      const newSelectedItems = [...selectedItems, selectedOption];
      setSelectedItems(newSelectedItems);
      onChange(newSelectedItems.map(item => item.name));
    }
  };

  const handleRemoveItem = (id: number) => {
    const newSelectedItems = selectedItems.filter(item => item.id !== id);
    setSelectedItems(newSelectedItems);
    onChange(newSelectedItems.map(item => item.name));
  };

  const availableOptions = options.filter(option =>
    !selectedItems.some(selected => selected.id === option.id)
  );

  return (
    <Box width="100%">
      <Flex direction="column" gap="2">
        {selectedItems.length > 0 && (
          <Flex wrap="wrap" gap="1">
            {selectedItems.map(item => (
              <Badge key={item.id} variant="soft" className="selectedBadge">
                {item.name}
                <Button
                  size="1"
                  variant="ghost"
                  onClick={() => handleRemoveItem(item.id)}
                  className="removeButton"
                  disabled={disabled}
                >
                  ×
                </Button>
              </Badge>
            ))}
          </Flex>
        )}

        <Select.Root onValueChange={handleSelectChange} value="" disabled={disabled}>
          <Select.Trigger placeholder={placeholder} />
          <Select.Content>
            {availableOptions.map(option => (
              <Select.Item key={option.id} value={option.name} style={{ cursor: 'pointer' }}>
                {option.name}
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      </Flex>
    </Box>
  );
}
