import { Flex, Text, Box, Checkbox } from '@radix-ui/themes';

export interface Library {
  id: number;
  name: string;
  path: string;
  type: string;
}

export interface LibrarySelectorProps {
  libraries: Library[];
  selectedIds: number[];
  onSelect: (ids: number[]) => void;
  loading?: boolean;
}

export function LibrarySelector({
  libraries,
  selectedIds,
  onSelect,
  loading = false,
}: LibrarySelectorProps) {
  const handleToggle = (libraryId: number) => {
    if (selectedIds.includes(libraryId)) {
      onSelect(selectedIds.filter((id) => id !== libraryId));
    } else {
      onSelect([...selectedIds, libraryId]);
    }
  };

  if (loading) {
    return (
      <Flex direction="column" gap="3">
        <Text weight="bold">Select Libraries</Text>
        <Text color="gray">Loading libraries...</Text>
      </Flex>
    );
  }

  return (
    <Flex direction="column" gap="3">
      <Flex justify="between" align="center">
        <Text weight="bold">Select Libraries</Text>
        <Text size="2" color="gray">
          {selectedIds.length} selected
        </Text>
      </Flex>

      {libraries.length === 0 ? (
        <Text color="gray">No libraries available</Text>
      ) : (
        <Flex direction="column" gap="2">
          {libraries.map((library) => (
            <Flex
              key={library.id}
              align="center"
              gap="3"
              p="3"
              style={{
                border: '1px solid var(--gray-6)',
                borderRadius: 'var(--radius-3)',
                cursor: 'pointer',
                backgroundColor: selectedIds.includes(library.id)
                  ? 'var(--gray-4)'
                  : 'transparent',
              }}
              onClick={() => handleToggle(library.id)}
            >
              <Checkbox
                checked={selectedIds.includes(library.id)}
                onChange={() => handleToggle(library.id)}
              />
              <Box flexGrow="1">
                <Flex direction="column">
                  <Text weight="medium">{library.name}</Text>
                  <Text size="2" color="gray">
                    {library.path}
                  </Text>
                </Flex>
              </Box>
              <Text size="2" color="gray">
                {library.type}
              </Text>
            </Flex>
          ))}
        </Flex>
      )}
    </Flex>
  );
}
