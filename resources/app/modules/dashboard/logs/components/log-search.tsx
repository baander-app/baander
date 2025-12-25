import React, { useState } from 'react';
import { 
  TextField, 
  Button, 
  Flex, 
  Select, 
  Text,
  Box 
} from '@radix-ui/themes';
import { MagnifyingGlassIcon, Cross2Icon } from '@radix-ui/react-icons';
import { ToggleGroup } from 'radix-ui';
import { useDateFormatter } from '@/app/providers/dayjs-provider.tsx';

interface LogFile {
  id: string;
  fileName: string;
  path: string;
  createdAt: string;
  updatedAt: string;
}

interface LogSearchProps {
  logFiles: LogFile[];
  selectedLogFile: string | null;
  onSelectLogFile: (logFileId: string) => void;
  searchQuery: string;
  onSearchQueryChange: (query: string) => void;
  viewMode: 'tail' | 'head' | 'content' | 'search';
  onViewModeChange: (mode: 'tail' | 'head' | 'content' | 'search') => void;
  linesCount: number;
  onLinesCountChange: (count: number) => void;
}

export const LogSearch: React.FC<LogSearchProps> = ({
  logFiles,
  selectedLogFile,
  onSelectLogFile,
  searchQuery,
  onSearchQueryChange,
  viewMode,
  onViewModeChange,
  linesCount,
  onLinesCountChange,
}) => {
  const { formatDate } = useDateFormatter();
  const [localSearchQuery, setLocalSearchQuery] = useState(searchQuery);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSearchQueryChange(localSearchQuery);
  };

  const handleClearSearch = () => {
    setLocalSearchQuery('');
    onSearchQueryChange('');
  };

  return (
    <Box className="log-search" p="4" style={{ borderBottom: '1px solid var(--gray-4)' }}>
      <Flex direction={{ initial: 'column', md: 'row' }} gap="4" align={{ md: 'center' }}>
        {/* Log file selector */}
        <Flex align="center" gap="2">
          <Text size="2" color="gray">Log File:</Text>
          <Select.Root 
            value={selectedLogFile || ''} 
            onValueChange={(value) => onSelectLogFile(value)}
          >
            <Select.Trigger placeholder="Select a log file" />
            <Select.Content position="popper" sideOffset={5} style={{ maxWidth: '300px' }} >
              {logFiles.map((logFile) => (
                <Select.Item key={logFile.id} value={logFile.id}>
                  <Flex direction="column" style={{ maxWidth: '250px', overflow: 'hidden' }}>
                    <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{logFile.fileName}</Text>
                    <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Updated: {formatDate(logFile.updatedAt)}</Text>
                  </Flex>
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </Flex>

        {/* Search form */}
        <form onSubmit={handleSearchSubmit} style={{ flexGrow: 1 }}>
          <TextField.Root
            value={localSearchQuery}
            onChange={(e) => setLocalSearchQuery(e.target.value)}
            placeholder="Search logs..."
            size="3"
          >
            <TextField.Slot side="right">
              {localSearchQuery && (
                <Button 
                  variant="ghost" 
                  size="1" 
                  onClick={handleClearSearch}
                >
                  <Cross2Icon />
                </Button>
              )}
              <Button 
                variant="ghost" 
                size="1" 
                type="submit"
              >
                <MagnifyingGlassIcon />
              </Button>
            </TextField.Slot>
          </TextField.Root>
        </form>

        {/* View mode selector */}
        <Flex align="center" gap="2">
          <Text size="2" color="gray">View:</Text>
          <ToggleGroup.Root 
            type="single" 
            value={viewMode}
            onValueChange={(value) => {
              if (value) onViewModeChange(value as 'tail' | 'head' | 'content' | 'search');
            }}
          >
            <ToggleGroup.Item value="tail">Tail</ToggleGroup.Item>
            <ToggleGroup.Item value="head">Head</ToggleGroup.Item>
            <ToggleGroup.Item value="content">Content</ToggleGroup.Item>
          </ToggleGroup.Root>
        </Flex>

        {/* Lines count selector */}
        <Flex align="center" gap="2">
          <Text size="2" color="gray">Lines:</Text>
          <Select.Root 
            value={linesCount.toString()} 
            onValueChange={(value) => onLinesCountChange(Number(value))}
          >
            <Select.Trigger />
            <Select.Content position="popper" sideOffset={5}>
              <Select.Item value="50">50</Select.Item>
              <Select.Item value="100">100</Select.Item>
              <Select.Item value="200">200</Select.Item>
              <Select.Item value="500">500</Select.Item>
              <Select.Item value="1000">1000</Select.Item>
            </Select.Content>
          </Select.Root>
        </Flex>
      </Flex>
    </Box>
  );
};

export default LogSearch;
