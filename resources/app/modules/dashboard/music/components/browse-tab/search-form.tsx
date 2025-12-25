import React, { useState, useEffect } from 'react';
import { TextField, Button, Flex, Select, Text, Box } from '@radix-ui/themes';
import { MagnifyingGlassIcon, Cross2Icon } from '@radix-ui/react-icons';

export type SearchType = 'album' | 'artist' | 'song';
export type SearchProvider = 'all' | 'musicbrainz' | 'discogs';

export interface SearchFormProps {
  onSearch: (query: string, type: SearchType, provider: SearchProvider) => void;
  isLoading?: boolean;
  defaultType?: SearchType;
  defaultQuery?: string;
}

export function SearchForm({ onSearch, isLoading, defaultType, defaultQuery }: SearchFormProps) {
  const [query, setQuery] = useState(defaultQuery || '');
  const [type, setType] = useState<SearchType>(defaultType || 'album');
  const [provider, setProvider] = useState<SearchProvider>('all');
  const [debouncedQuery, setDebouncedQuery] = useState(defaultQuery || '');

  // Update query when defaultQuery changes
  useEffect(() => {
    if (defaultQuery !== undefined && defaultQuery !== query) {
      setQuery(defaultQuery);
      setDebouncedQuery(defaultQuery);
    }
  }, [defaultQuery]);

  // Debounce search input (300ms)
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedQuery(query);
    }, 300);
    return () => clearTimeout(handler);
  }, [query]);

  // Auto-search when debounced query changes
  useEffect(() => {
    if (debouncedQuery.trim()) {
      onSearch(debouncedQuery, type, provider);
    }
  }, [debouncedQuery, type, provider, onSearch]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (query.trim()) {
      onSearch(query, type, provider);
    }
  };

  const handleClear = () => {
    setQuery('');
    setDebouncedQuery('');
  };

  return (
    <Box p="4" style={{ borderBottom: '1px solid var(--gray-4)' }}>
      <form onSubmit={handleSearch}>
        <Flex direction={{ initial: 'column', md: 'row' }} gap="4" align={{ md: 'center' }}>
          {/* Type selector - only show if no default type */}
          {!defaultType && (
            <Flex align="center" gap="2">
              <Text size="2" color="gray">Type:</Text>
              <Select.Root
                value={type}
                onValueChange={(value) => setType(value as SearchType)}
              >
                <Select.Trigger placeholder="Select type" />
                <Select.Content position="popper" sideOffset={5}>
                  <Select.Item value="album">Album</Select.Item>
                  <Select.Item value="artist">Artist</Select.Item>
                  <Select.Item value="song">Song</Select.Item>
                </Select.Content>
              </Select.Root>
            </Flex>
          )}

          {/* Provider filter */}
          <Flex align="center" gap="2">
            <Text size="2" color="gray">Provider:</Text>
            <Select.Root
              value={provider}
              onValueChange={(value) => setProvider(value as SearchProvider)}
            >
              <Select.Trigger placeholder="Select provider" />
              <Select.Content position="popper" sideOffset={5}>
                <Select.Item value="all">All</Select.Item>
                <Select.Item value="musicbrainz">MusicBrainz</Select.Item>
                <Select.Item value="discogs">Discogs</Select.Item>
              </Select.Content>
            </Select.Root>
          </Flex>

          {/* Search input */}
          <Flex style={{ flexGrow: 1 }}>
            <TextField.Root
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search for albums, artists, or songs..."
              size="3"
              style={{ width: '100%' }}
              disabled={isLoading}
            >
              <TextField.Slot side="right">
                {query && (
                  <Button
                    variant="ghost"
                    size="1"
                    onClick={handleClear}
                    disabled={isLoading}
                  >
                    <Cross2Icon />
                  </Button>
                )}
                <Button
                  variant="ghost"
                  size="1"
                  type="submit"
                  disabled={isLoading || !query.trim()}
                >
                  <MagnifyingGlassIcon />
                </Button>
              </TextField.Slot>
            </TextField.Root>
          </Flex>
        </Flex>
      </form>
    </Box>
  );
}
