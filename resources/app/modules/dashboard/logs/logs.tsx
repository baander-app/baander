import React, { useState, useEffect } from 'react';
import { LogViewer } from './components/log-viewer.tsx';
import { LogSearch } from './components/log-search.tsx';
import { LogStats } from './components/log-stats.tsx';
import { LoadingSpinner } from '@/modules/common/LoadingSpinner.tsx';
import { ErrorDisplay } from '@/modules/common/ErrorDisplay.tsx';
import { 
  Box, 
  Flex,
  Container,
} from '@radix-ui/themes';
import './logs.css';
import { useLogsIndex } from '@/libs/api-client/gen/endpoints/logs/logs.ts';

export const LogsPage: React.FC = () => {
  const [selectedLogFile, setSelectedLogFile] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [viewMode, setViewMode] = useState<'tail' | 'head' | 'content' | 'search'>('tail');
  const [linesCount, setLinesCount] = useState<number>(50);

  // Fetch log files
  const { 
    data: logFiles, 
    isLoading: isLoadingLogFiles, 
    error: logFilesError 
  } = useLogsIndex();

  // Select first log file by default if none selected
  useEffect(() => {
    if (logFiles && logFiles.length > 0 && !selectedLogFile) {
      setSelectedLogFile(logFiles[0].id);
    }
  }, [logFiles, selectedLogFile]);

  // Handle log file selection
  const handleLogFileSelect = (logFileId: string) => {
    setSelectedLogFile(logFileId);
    // Reset view mode to tail when changing log files
    setViewMode('tail');
  };

  // Handle search query change
  const handleSearchQueryChange = (query: string) => {
    setSearchQuery(query);
    if (query) {
      setViewMode('search');
    } else {
      setViewMode('tail');
    }
  };

  // Handle view mode change
  const handleViewModeChange = (mode: 'tail' | 'head' | 'content' | 'search') => {
    setViewMode(mode);
  };

  // Handle lines count change
  const handleLinesCountChange = (count: number) => {
    setLinesCount(count);
  };

  if (isLoadingLogFiles) {
    return (
      <Flex justify="center" align="center" style={{ height: '100vh' }}>
        <LoadingSpinner />
      </Flex>
    );
  }

  if (logFilesError) {
    return (
      <Container size="4" p="4">
        <ErrorDisplay error={logFilesError} />
      </Container>
    );
  }

  return (
    <Box className="logs-page" style={{ height: '100vh', backgroundColor: 'var(--gray-1)' }}>
      <Flex className="logs-container" style={{ height: '100%', overflow: 'hidden' }}>
        <Box 
          className="main-content" 
          style={{ 
            flex: 1, 
            display: 'flex', 
            flexDirection: 'column',
            overflow: 'hidden'
          }}
        >
          <Box className="toolbar" style={{ flexShrink: 0 }}>
            <LogSearch 
              logFiles={logFiles || []}
              selectedLogFile={selectedLogFile}
              onSelectLogFile={handleLogFileSelect}
              searchQuery={searchQuery} 
              onSearchQueryChange={handleSearchQueryChange}
              viewMode={viewMode}
              onViewModeChange={handleViewModeChange}
              linesCount={linesCount}
              onLinesCountChange={handleLinesCountChange}
            />
          </Box>
          <Box 
            className="log-viewer-container" 
            style={{ 
              flex: 1, 
              display: 'flex', 
              flexDirection: 'column',
              overflow: 'hidden'
            }}
          >
            {selectedLogFile && (
              <>
                <LogViewer 
                  logFileId={selectedLogFile} 
                  viewMode={viewMode}
                  searchQuery={searchQuery}
                  linesCount={linesCount}
                />
                <LogStats logFileId={selectedLogFile} />
              </>
            )}
          </Box>
        </Box>
      </Flex>
    </Box>
  );
};

export default LogsPage;
