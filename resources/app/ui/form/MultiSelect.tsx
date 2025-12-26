import { useState, useEffect, useMemo, useRef } from 'react';
import { Box, Badge, Button, Flex, TextField, Text, Popover } from '@radix-ui/themes';

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
 * - Search/filter input inside dropdown for finding items
 * - Max height with scrollbar for dropdown
 * - Badge display of selected items with remove button
 * - Dropdown stays open for multiple selections
 */
export function MultiSelect({ placeholder, value, onChange, options, disabled }: MultiSelectProps) {
  const [selectedItems, setSelectedItems] = useState<{ id: number; name: string }[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const searchInputRef = useRef<HTMLInputElement>(null);

  // Initialize selected items from value prop
  useEffect(() => {
    const selected = options.filter(item => value.includes(item.name));
    setSelectedItems(selected);
  }, [value, options]);

  const handleSelectOption = (option: { id: number; name: string }) => {
    if (!selectedItems.some(item => item.id === option.id)) {
      const newSelectedItems = [...selectedItems, option];
      setSelectedItems(newSelectedItems);
      onChange(newSelectedItems.map(item => item.name));
      // Keep focus on search input for continuous selection
      setTimeout(() => searchInputRef.current?.focus(), 0);
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

        <Popover.Root modal={false}>
          <Popover.Trigger>
            <Box
              style={{
                width: '100%',
                minHeight: 'var(--space-5)',
                padding: '0 var(--space-3)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                backgroundColor: 'var(--color-surface)',
                border: '1px solid var(--gray-7)',
                borderRadius: 'var(--radius-3)',
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.5 : 1,
              }}
              className="multiSelectTrigger"
            >
              <Text size="2" style={{ color: selectedItems.length === 0 ? 'var(--gray-9)' : 'inherit', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {selectedItems.length === 0 ? placeholder : selectedItems.map(item => item.name).join(', ')}
              </Text>
              <Text size="2" style={{ color: 'var(--gray-9)', flexShrink: 0, marginLeft: 'var(--space-2)' }}>▼</Text>
            </Box>
          </Popover.Trigger>

          <Popover.Content
            style={{
              width: 'var(--radix-popover-trigger-width)',
              maxHeight: '250px',
              overflow: 'auto'
            }}
            onOpenAutoFocus={(event) => {
              event.preventDefault();
              searchInputRef.current?.focus();
            }}
          >
            {/* Search Input inside dropdown */}
            <Box p="2" mb="2" style={{ borderBottom: '1px solid var(--gray-6)' }}>
              <TextField.Root
                placeholder="Search..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                disabled={disabled}
                ref={searchInputRef}
                onKeyDown={(e) => {
                  // Prevent dropdown from closing when typing
                  if (e.key === 'Enter') {
                    e.preventDefault();
                  }
                }}
              >
                <TextField.Slot />
              </TextField.Root>
            </Box>

            <Box>
              {availableOptions.length === 0 ? (
                <Text size="2" color="gray" style={{ padding: '8px 16px' }}>
                  {searchQuery ? 'No results found' : 'All items selected'}
                </Text>
              ) : (
                <Flex direction="column">
                  {availableOptions.map(option => (
                    <Box
                      key={option.id}
                      p="2"
                      style={{
                        cursor: 'pointer',
                        borderRadius: '4px',
                      }}
                      className="multiSelectOption"
                      onMouseEnter={(e) => {
                        e.currentTarget.style.backgroundColor = 'var(--gray-4)';
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.backgroundColor = 'transparent';
                      }}
                      onClick={() => handleSelectOption(option)}
                    >
                      <Text size="2">{option.name}</Text>
                    </Box>
                  ))}
                </Flex>
              )}
            </Box>
          </Popover.Content>
        </Popover.Root>
      </Flex>

      <style>{`
        .multiSelectTrigger:hover {
          background-color: var(--gray-a3);
        }
        .multiSelectTrigger:focus-within {
          outline: 2px solid var(--focus-outline);
          outline-offset: 2px;
        }
        .multiSelectOption:hover {
          background-color: var(--gray-4);
        }
      `}</style>
    </Box>
  );
}
