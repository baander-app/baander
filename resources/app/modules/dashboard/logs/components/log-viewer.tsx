
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { VirtualLogViewer } from './virtual-log-viewer.tsx';
import { JsonLogLine } from './json-log-line.tsx';
import { LineJumpNavigation } from './line-jump-navigation.tsx';
import {
  useLogsServiceGetApiLogsByLogFileContent,
  useLogsServiceGetApiLogsByLogFileSearch,
  useLogsServiceGetApiLogsByLogFileTail,
  useLogsServiceGetApiLogsByLogFileHead,
  useLogsServiceGetApiLogsByLogFileLines
} from '@/api-client/queries';
import { LoadingSpinner } from '@/modules/common/LoadingSpinner.tsx';
import { ErrorDisplay } from '@/modules/common/ErrorDisplay.tsx';
import {
  Box,
  Flex,
  Text,
  ScrollArea,
  Em
} from '@radix-ui/themes';
import { MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { useDebounce } from 'ahooks';

interface LogViewerProps {
  logFileId: string;
  viewMode: 'tail' | 'head' | 'content' | 'search';
  searchQuery: string;
  linesCount: number;
}

interface LogLine {
  line: number;
  content: string;
}

interface SearchResult {
  line: number;
  content: string;
}

interface PaginatedLogState {
  lines: LogLine[];
  currentStartLine: number;
  currentEndLine: number;
  totalLines: number;
  hasMoreBefore: boolean;
  hasMoreAfter: boolean;
}

// Simple Log Display Component (only for very small datasets with no infinite scroll potential)
const SimpleLogDisplay: React.FC<{
  lines: LogLine[];
  height: number;
}> = ({ lines, height }) => {
  const getLogLevelStyle = (content: string) => {
    const upperContent = content.toUpperCase();
    if (upperContent.includes('ERROR') || upperContent.includes('FATAL')) {
      return {
        color: '#ff6b6b',
        backgroundColor: '#ff6b6b08',
        borderLeft: '3px solid #ff6b6b20'
      };
    }
    if (upperContent.includes('WARN')) {
      return {
        color: '#ffa726',
        backgroundColor: '#ffa72608',
        borderLeft: '3px solid #ffa72620'
      };
    }
    if (upperContent.includes('INFO')) {
      return {
        color: '#42a5f5',
        backgroundColor: '#42a5f508',
        borderLeft: '3px solid #42a5f520'
      };
    }
    if (upperContent.includes('DEBUG')) {
      return {
        color: '#9e9e9e',
        borderLeft: '3px solid transparent'
      };
    }
    return {
      color: '#e0e0e0',
      borderLeft: '3px solid transparent'
    };
  };

  return (
    <ScrollArea
      style={{
        height: height,
        backgroundColor: '#0f172a',
        borderRadius: '12px',
        border: '1px solid #1e293b',
      }}
    >
      <Box style={{ padding: '8px' }}>
        {lines.map((line, index) => {
          const logStyle = getLogLevelStyle(line.content);
          return (
            <div
              key={`${line.line}-${index}`}
              style={{
                marginBottom: '1px',
                borderRadius: '4px',
                transition: 'background-color 0.15s ease',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = '#ffffff06';
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = 'transparent';
              }}
            >
              <JsonLogLine
                line={line.line}
                content={line.content}
                logStyle={logStyle}
              />
            </div>
          );
        })}
      </Box>
    </ScrollArea>
  );
};

// Search Results Component
const SearchResults: React.FC<{
  results: SearchResult[];
  totalMatches: number;
  searchQuery: string;
}> = ({ results, totalMatches, searchQuery }) => (
  <Box>
    <Box
      style={{
        backgroundColor: '#1e293b',
        borderRadius: '8px',
        border: '1px solid #334155',
        padding: '12px 16px',
        marginBottom: '16px',
      }}
    >
      <Flex align="center" gap="3">
        <MagnifyingGlassIcon width="16" height="16" style={{ color: '#64748b' }} />
        <Text style={{ color: '#e2e8f0', fontSize: '14px', fontWeight: '500' }}>
          Found <span style={{ color: '#3b82f6', fontWeight: '600' }}>{totalMatches}</span> matches for
          <Em style={{ color: '#fbbf24', fontWeight: '500', marginLeft: '4px' }}>"{searchQuery}"</Em>
        </Text>
      </Flex>
    </Box>

    <Box style={{ gap: '4px', display: 'flex', flexDirection: 'column' }}>
      {results.map((result, index) => (
        <Box
          key={index}
          style={{
            borderRadius: '6px',
            border: '1px solid #374151',
            backgroundColor: '#111827',
            padding: '4px 8px',
          }}
        >
          <JsonLogLine
            line={result.line}
            content={result.content}
            logStyle={{
              color: '#fbbf24',
              backgroundColor: 'transparent'
            }}
          />
        </Box>
      ))}
    </Box>
  </Box>
);

export const LogViewer: React.FC<LogViewerProps> = ({
                                                      logFileId,
                                                      viewMode,
                                                      searchQuery,
                                                      linesCount,
                                                    }) => {
  const [paginatedState, setPaginatedState] = useState<PaginatedLogState>({
    lines: [],
    currentStartLine: 1,
    currentEndLine: 0,
    totalLines: 0,
    hasMoreBefore: false,
    hasMoreAfter: false
  });

  const debouncedSearchQuery = useDebounce(searchQuery, {
    wait: 300
  });
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [currentAfterLine, setCurrentAfterLine] = useState(0);

  // Clear state when viewMode changes
  useEffect(() => {
    setPaginatedState({
      lines: [],
      currentStartLine: 1,
      currentEndLine: 0,
      totalLines: 0,
      hasMoreBefore: false,
      hasMoreAfter: false
    });
    setCurrentAfterLine(0);
    setIsLoadingMore(false);
  }, [viewMode, logFileId]);

  // Get total lines count
  const {
    data: linesData,
    isLoading: isLoadingLines,
  } = useLogsServiceGetApiLogsByLogFileLines(
    { logFile: logFileId },
    undefined,
    { enabled: !!logFileId }
  );

  // Content endpoint with pagination - ONLY for content mode
  const {
    data: contentData,
    isLoading: isLoadingContent,
    error: contentError,
  } = useLogsServiceGetApiLogsByLogFileContent(
    {
      logFile: logFileId,
      maxLines: Math.min(linesCount, 5000),
      afterLine: currentAfterLine
    },
    undefined,
    {
      enabled: viewMode === 'content' && !!logFileId,
      refetchOnWindowFocus: false,
    }
  );

  // Tail endpoint - ONLY for tail mode
  const {
    data: tailData,
    isLoading: isLoadingTail,
    error: tailError,
  } = useLogsServiceGetApiLogsByLogFileTail(
    {
      logFile: logFileId,
      lines: Math.min(linesCount, 1000)
    },
    undefined,
    {
      enabled: viewMode === 'tail' && !!logFileId,
      refetchOnWindowFocus: false,
    }
  );

  // Head endpoint - ONLY for head mode
  const {
    data: headData,
    isLoading: isLoadingHead,
    error: headError,
  } = useLogsServiceGetApiLogsByLogFileHead(
    {
      logFile: logFileId,
      lines: Math.min(linesCount, 1000)
    },
    undefined,
    {
      enabled: viewMode === 'head' && !!logFileId,
      refetchOnWindowFocus: false,
    }
  );

  // Search endpoint - ONLY for search mode
  const {
    data: searchData,
    isLoading: isLoadingSearch,
    error: searchError,
  } = useLogsServiceGetApiLogsByLogFileSearch(
    {
      logFile: logFileId,
      pattern: debouncedSearchQuery,
      maxResults: 1000,
      caseSensitive: false
    },
    undefined,
    {
      enabled: viewMode === 'search' && !!logFileId && debouncedSearchQuery.length > 2,
      refetchOnWindowFocus: false,
    }
  );

  // Process log data helper
  const processLogData = useCallback((data: any): LogLine[] => {
    if (!data) return [];

    // Handle the backend response structure: { content: string, startLine: number, ... }
    if (data && typeof data === 'object' && data.content && typeof data.content === 'string') {
      const startLine = data.startLine || 1;
      return data.content.split('\n')
        .filter(line => line.trim() !== '')
        .map((content, index) => ({
          line: startLine + index,
          content: content.trim()
        }));
    }

    // Handle direct string data
    if (typeof data === 'string') {
      return data.split('\n')
        .filter(line => line.trim() !== '')
        .map((content, index) => ({
          line: index + 1,
          content: content.trim()
        }));
    }

    // Handle array data
    if (Array.isArray(data)) {
      return data.map((line, index) => ({
        line: line.line || index + 1,
        content: line.content || String(line)
      }));
    }

    return [];
  }, []);

  // Process and update state when data changes
  useEffect(() => {
    const totalLines = linesData?.data?.totalLines || 0;
    let processedLines: LogLine[] = [];
    let startLine = 1;
    let endLine = 0;
    let hasMoreBefore = false;
    let hasMoreAfter = false;

    // Only process data for the current view mode
    if (viewMode === 'content' && contentData?.data) {
      processedLines = processLogData(contentData.data);
      startLine = contentData.data.startLine || 1;
      endLine = contentData.data.endLine || (startLine + processedLines.length - 1);
      hasMoreBefore = startLine > 1;
      hasMoreAfter = contentData.data.hasMore || false;
    } else if (viewMode === 'tail' && tailData?.data?.content) {
      processedLines = processLogData(tailData.data.content);
      startLine = tailData.data.content.startLine || (totalLines - processedLines.length + 1);
      endLine = tailData.data.content.endLine || totalLines;
      hasMoreBefore = startLine > 1;
      hasMoreAfter = false; // tail is always at the end
    } else if (viewMode === 'head' && headData?.data?.content) {
      processedLines = processLogData(headData.data.content);
      startLine = headData.data.content.startLine || 1;
      endLine = headData.data.content.endLine || processedLines.length;
      hasMoreBefore = false; // head starts at the beginning
      hasMoreAfter = headData.data.content.hasMore || false;
    }

    if (processedLines.length > 0) {
      setPaginatedState({
        lines: processedLines,
        currentStartLine: startLine,
        currentEndLine: endLine,
        totalLines,
        hasMoreBefore,
        hasMoreAfter
      });
    }
  }, [contentData, tailData, headData, linesData, viewMode, processLogData]);

  // Load more content - now works for all view modes
  const loadMoreContent = useCallback(async (direction: 'before' | 'after') => {
    if (isLoadingMore || !logFileId) return;

    console.log(`🚀 Loading more ${direction} for ${viewMode} mode`);
    setIsLoadingMore(true);

    try {
      if (viewMode === 'content') {
        // Content mode uses the content endpoint
        const chunkSize = 2000;
        let afterLine: number;

        if (direction === 'after') {
          afterLine = paginatedState.currentEndLine;
        } else {
          afterLine = Math.max(0, paginatedState.currentStartLine - chunkSize);
        }

        setCurrentAfterLine(afterLine);
      } else {
        // For head/tail modes, we need to refetch with different parameters
        // This would require extending the API or using the content endpoint
        console.log(`TODO: Implement infinite scroll for ${viewMode} mode`);

        // For now, we can switch to content mode for infinite scrolling
        // or implement specific logic for head/tail infinite scroll
      }
    } catch (error) {
      console.error('Failed to load more content:', error);
    } finally {
      setIsLoadingMore(false);
    }
  }, [logFileId, paginatedState, isLoadingMore, viewMode]);

  // Jump to specific line using content endpoint
  const jumpToLine = useCallback((targetLine: number) => {
    if (!logFileId || isLoadingMore || viewMode !== 'content') return;

    const chunkSize = 2000;
    const startLine = Math.max(0, targetLine - chunkSize / 2);
    setCurrentAfterLine(startLine);
  }, [logFileId, isLoadingMore, viewMode]);

  // Determine current loading state
  const isLoading = useMemo(() => {
    switch (viewMode) {
      case 'content': return isLoadingContent;
      case 'tail': return isLoadingTail;
      case 'head': return isLoadingHead;
      case 'search': return isLoadingSearch;
      default: return false;
    }
  }, [viewMode, isLoadingContent, isLoadingTail, isLoadingHead, isLoadingSearch]);

  // Determine current error
  const error = useMemo(() => {
    switch (viewMode) {
      case 'content': return contentError;
      case 'tail': return tailError;
      case 'head': return headError;
      case 'search': return searchError;
      default: return null;
    }
  }, [viewMode, contentError, tailError, headError, searchError]);

  const viewerHeight = useMemo(() => {
    const windowHeight = window.innerHeight;
    const baseOffset = 200; // Space for header, controls, etc.
    const navigationHeight = viewMode === 'content' ? 60 : 0; // Navigation bar height
    return windowHeight - baseOffset - navigationHeight;
  }, [viewMode]);

  // Render loading state
  if (isLoadingLines || (isLoading && paginatedState.lines.length === 0)) {
    return (
      <Flex justify="center" align="center" style={{ height: `${viewerHeight}px` }}>
        <LoadingSpinner />
      </Flex>
    );
  }

  // Render error state
  if (error) {
    return <ErrorDisplay error={error} />;
  }

  // Render search results
  if (viewMode === 'search') {
    if (!searchData?.data?.results || searchData.data.results.length === 0) {
      return (
        <Flex direction="column" justify="center" align="center" style={{ height: `${viewerHeight}px` }}>
          <Box style={{ marginBottom: '16px', opacity: 0.6 }}>
            <MagnifyingGlassIcon width="48" height="48" style={{ color: '#6b7280' }} />
          </Box>
          <Text style={{ color: '#6b7280', fontSize: '14px' }}>
            No search results found for "<Em style={{ color: '#9ca3af' }}>{debouncedSearchQuery}</Em>"
          </Text>
        </Flex>
      );
    }

    return (
      <ScrollArea style={{ height: `${viewerHeight}px` }}>
        <Box style={{ padding: '16px' }}>
          <SearchResults
            results={searchData.data.results}
            totalMatches={searchData.data.totalMatches || 0}
            searchQuery={debouncedSearchQuery}
          />
        </Box>
      </ScrollArea>
    );
  }

  // Determine if we should use virtual scrolling
  // Use virtual scrolling when:
  // 1. There are more than 20 lines, OR
  // 2. There's potential for infinite scrolling (hasMoreBefore or hasMoreAfter)
  const shouldUseVirtualScrolling =
    paginatedState.lines.length > 20 ||
    paginatedState.hasMoreBefore ||
    paginatedState.hasMoreAfter;

  console.log('Rendering decision:', {
    viewMode,
    linesCount: paginatedState.lines.length,
    hasMoreBefore: paginatedState.hasMoreBefore,
    hasMoreAfter: paginatedState.hasMoreAfter,
    shouldUseVirtualScrolling
  });

  return (
    <Box>
      {shouldUseVirtualScrolling ? (
        <VirtualLogViewer
          logLines={paginatedState.lines}
          height={viewerHeight}
          onLoadMore={loadMoreContent}
          totalLines={paginatedState.totalLines}
          currentStartLine={paginatedState.currentStartLine}
          hasMoreBefore={paginatedState.hasMoreBefore}
          hasMoreAfter={paginatedState.hasMoreAfter}
          isLoading={isLoadingMore}
        />
      ) : (
         <SimpleLogDisplay
           lines={paginatedState.lines}
           height={viewerHeight}
         />
       )}

      {/* Navigation controls - only show for content mode */}
      {viewMode === 'content' && (
        <LineJumpNavigation
          totalLines={paginatedState.totalLines}
          currentLine={paginatedState.currentStartLine}
          onJumpToLine={jumpToLine}
          isLoading={isLoadingMore}
          hasMoreBefore={paginatedState.hasMoreBefore}
          hasMoreAfter={paginatedState.hasMoreAfter}
          onLoadMore={loadMoreContent}
        />
      )}
    </Box>
  );
};

export default LogViewer;