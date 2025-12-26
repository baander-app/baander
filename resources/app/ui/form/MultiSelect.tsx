import { useState, useEffect, useMemo } from 'react';
import { Box, Select, Badge, Button, Flex, TextField, Text } from '@radix-ui/themes';

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
 *
 * Features:
 * - Search/filter input for finding items
 * - Max height with scrollbar for dropdown
 * - Badge display of selected items with remove button
 */
export function MultiSelect({ placeholder, value, onChange, options, disabled }: MultiSelectProps) {
  const [selectedItems, setSelectedItems] = useState<{ id: number; name: string }[]>([]);
  const [searchQuery, setSearchQuery] = useState('');

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
      setSearchQuery(''); // Clear search after selection
      onChange(newSelectedItems.map(item => item.name));
    }
  };

  const handleRemoveItem = (id: number) => {
    const newSelectedItems = selectedItems.filter(item => item.id !== id);
    setSelectedItems(newSelectedItems);
    onChange(newSelectedItems.map(item => item.name));
  };

  // Filter available options (exclude selected) and filter by search query
  const availableOptions = useMemo(() => {
    const unselected = options.filter(option =>
      !selectedItems.some(selected => selected.id === option.id)
    );

    if (!searchQuery.trim()) {
      return unselected;
    }

    const query = searchQuery.toLowerCase();
    return unselected.filter(option =>
      option.name.toLowerCase().includes(query)
    );
  }, [options, selectedItems, searchQuery]);

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

        {/* Search Input */}
        <TextField.Root
          placeholder="Search..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          disabled={disabled}
        >
          <TextField.Slot />
        </TextField.Root>

        <Select.Root
          onValueChange={handleSelectChange}
          value=""
          disabled={disabled}
        >
          <Select.Trigger placeholder={placeholder} />
          <Select.Content maxHeight="200px">
            {availableOptions.length === 0 ? (
              <Text size="2" color="gray" style={{ padding: '8px 16px' }}>
                {searchQuery ? 'No results found' : 'All items selected'}
              </Text>
            ) : (
              availableOptions.map(option => (
                <Select.Item
                  key={option.id}
                  value={option.name}
                  style={{ cursor: 'pointer' }}
                >
                  {option.name}
                </Select.Item>
              ))
            )}
          </Select.Content>
        </Select.Root>
      </Flex>
    </Box>
  );
}
