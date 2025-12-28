import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { VirtualLogViewer } from './virtual-log-viewer.tsx';
import { JsonLogLine } from './json-log-line.tsx';
import { LineJumpNavigation } from './line-jump-navigation.tsx';
import { LoadingSpinner } from '@/app/modules/common/LoadingSpinner.tsx';
import { ErrorDisplay } from '@/app/modules/common/ErrorDisplay.tsx';
import { Box, Em, Flex, ScrollArea, Text } from '@radix-ui/themes';
import { MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { useDebounce } from 'ahooks';
import {
  useLogsContent,
  useLogsHead, useLogsSearch,
  useLogsShow,
  useLogsTail,
} from '@/app/libs/api-client/gen/endpoints/system/system.ts';

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
        color: '#f87171',
        borderLeft: '3px solid #f87171',
        backgroundColor: '#f8717112',
      };
    }
    if (upperContent.includes('WARN')) {
      return {
        color: '#fbbf24',
        borderLeft: '3px solid #fbbf24',
        backgroundColor: '#fbbf2412',
      };
    }
    if (upperContent.includes('INFO')) {
      return {
        color: '#60a5fa',
        borderLeft: '3px solid #60a5fa',
        backgroundColor: '#60a5fa0a',
      };
    }
    if (upperContent.includes('DEBUG')) {
      return {
        color: '#9ca3af',
        borderLeft: '3px solid transparent',
        backgroundColor: 'transparent',
      };
    }
    return {
      color: '#d1d5db',
      borderLeft: '3px solid transparent',
      backgroundColor: 'transparent',
    };
  };

  return (
    <ScrollArea
      style={{
        height: height,
        backgroundColor: '#0a0a0a',
        borderRadius: '8px',
        border: '1px solid #27272a',
      }}
    >
      <Box style={{ padding: '12px' }}>
        {lines.map((line, index) => {
          const logStyle = getLogLevelStyle(line.content);
          return (
            <div
              key={`${line.line}-${index}`}
              style={{
                marginBottom: '2px',
                borderRadius: '4px',
                transition: 'all 0.15s ease',
                borderLeft: logStyle.borderLeft,
                paddingLeft: '8px',
                backgroundColor: logStyle.backgroundColor || 'transparent',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = '#ffffff0f';
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = logStyle.backgroundColor || 'transparent';
              }}
            >
              <JsonLogLine
                line={line.line}
                content={line.content}
                logStyle={{
                  ...logStyle,
                  borderLeft: 'none',
                }}
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
        backgroundColor: '#18181b',
        borderRadius: '8px',
        border: '1px solid #27272a',
        padding: '12px 16px',
        marginBottom: '16px',
      }}
    >
      <Flex align="center" gap="3">
        <MagnifyingGlassIcon width="16" height="16" style={{ color: '#71717a' }}/>
        <Text style={{ color: '#fafafa', fontSize: '14px', fontWeight: '500' }}>
          Found <span style={{ color: '#60a5fa', fontWeight: '600' }}>{totalMatches}</span> matches for
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
            border: '1px solid #27272a',
            backgroundColor: '#0a0a0a',
            padding: '4px 8px',
          }}
        >
          <JsonLogLine
            line={result.line}
            content={result.content}
            logStyle={{
              color: '#fbbf24',
              backgroundColor: 'transparent',
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
    hasMoreAfter: false,
  });

  const debouncedSearchQuery = useDebounce(searchQuery, {
    wait: 300,
  });
  const [currentAfterLine, setCurrentAfterLine] = useState(0);

  // Clear state when viewMode changes
  useEffect(() => {
    setPaginatedState({
      lines: [],
      currentStartLine: 1,
      currentEndLine: 0,
      totalLines: 0,
      hasMoreBefore: false,
      hasMoreAfter: false,
    });
    setCurrentAfterLine(0);
  }, [viewMode, logFileId]);

  // Get total lines count
  const {
    data: linesData,
    isLoading: isLoadingLines,
  } = useLogsShow(logFileId,
    {
      query: { enabled: !!logFileId },
    },
  );

  // Content endpoint with pagination - ONLY for content mode
  const {
    data: contentData,
    isLoading: isLoadingContent,
    error: contentError,
  } = useLogsContent(logFileId,
    {
      max_lines: Math.min(linesCount, 5000),
      after_line: currentAfterLine,
    },
    {
      query: {
        enabled: viewMode === 'content' && !!logFileId,
        refetchOnWindowFocus: false,
      },
    },
  );

  // Tail endpoint - ONLY for tail mode
  const {
    data: tailData,
    isLoading: isLoadingTail,
    error: tailError,
  } = useLogsTail(logFileId,
    {
      lines: Math.min(linesCount, 1000),
    },
    {
      query: {
        enabled: viewMode === 'tail' && !!logFileId,
        refetchOnWindowFocus: false,
      }
    },
  );

  // Head endpoint - ONLY for head mode
  const {
    data: headData,
    isLoading: isLoadingHead,
    error: headError,
  } = useLogsHead(
    logFileId,
    {
      lines: Math.min(linesCount, 1000),
    },
    {
      query: {
        enabled: viewMode === 'head' && !!logFileId,
        refetchOnWindowFocus: false,
      }
    },
  );

  // Search endpoint - ONLY for search mode
  const {
    data: searchData,
    isLoading: isLoadingSearch,
    error: searchError,
  } = useLogsSearch(logFileId,
    {
      pattern: debouncedSearchQuery,
      maxResults: 1000,
      caseSensitive: false,
    },
    {
      query: {
        enabled: viewMode === 'search' && !!logFileId,
        refetchOnWindowFocus: false,
      }
    },
  );

  // Process log data helper
  const processLogData = useCallback((data: any, startLineOverride?: number): LogLine[] => {
    if (!data) return [];

    // Handle the API response structure: { lines: string[], startLine, endLine, hasMore }
    if (data && typeof data === 'object' && Array.isArray(data.lines)) {
      const startLine = startLineOverride ?? data.startLine ?? 1;
      return data.lines
        .filter((line: string) => line.trim() !== '')
        .map((content: string, index: number) => ({
          line: startLine + index,
          content: content.trim(),
        }));
    }

    // Handle legacy structure: { content: string, startLine, endLine, hasMore }
    if (data && typeof data === 'object' && data.content && typeof data.content === 'string') {
      const startLine = data.startLine || 1;
      return data.content.split('\n')
        .filter(line => line.trim() !== '')
        .map((content, index) => ({
          line: startLine + index,
          content: content.trim(),
        }));
    }

    // Handle direct string data
    if (typeof data === 'string') {
      return data.split('\n')
        .filter(line => line.trim() !== '')
        .map((content, index) => ({
          line: index + 1,
          content: content.trim(),
        }));
    }

    // Handle array data
    if (Array.isArray(data)) {
      return data.map((line, index) => ({
        line: line.line || index + 1,
        content: line.content || String(line),
      }));
    }

    return [];
  }, []);

  // Process and update state when data changes
  useEffect(() => {
    const totalLines = linesData?.data?.info?.lines || linesData?.data?.totalLines || 0;
    let processedLines: LogLine[] = [];
    let startLine = 1;
    let endLine = 0;
    let hasMoreBefore = false;
    let hasMoreAfter = false;

    // Only process data for the current view mode
    if (viewMode === 'content' && contentData?.data) {
      const data = contentData.data;
      processedLines = processLogData(data);
      startLine = data.startLine ?? 1;
      endLine = data.endLine ?? (startLine + processedLines.length - 1);
      hasMoreBefore = startLine > 1;
      hasMoreAfter = data.hasMore ?? false;
    } else if (viewMode === 'tail' && tailData?.data?.content) {
      const data = tailData.data.content;
      processedLines = processLogData(data);
      startLine = data.startLine ?? (totalLines - processedLines.length + 1);
      endLine = data.endLine ?? totalLines;
      hasMoreBefore = startLine > 1;
      hasMoreAfter = false; // tail is always at the end
    } else if (viewMode === 'head' && headData?.data?.content) {
      const data = headData.data.content;
      processedLines = processLogData(data);
      startLine = data.startLine ?? 1;
      endLine = data.endLine ?? processedLines.length;
      hasMoreBefore = false; // head starts at the beginning
      hasMoreAfter = data.hasMore ?? false;
    }

    if (processedLines.length > 0) {
      setPaginatedState({
        lines: processedLines,
        currentStartLine: startLine,
        currentEndLine: endLine,
        totalLines,
        hasMoreBefore,
        hasMoreAfter,
      });
    }
  }, [contentData, tailData, headData, linesData, viewMode, processLogData]);

  // Load more content - now works for all view modes
  const loadMoreContent = useCallback((direction: 'before' | 'after') => {
    if (!logFileId) return;

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
    }
    // For head/tail modes, infinite scroll would require extending the API
  }, [logFileId, paginatedState, viewMode]);

  // Jump to specific line using content endpoint
  const jumpToLine = useCallback((targetLine: number) => {
    if (!logFileId || viewMode !== 'content') return;

    const chunkSize = 2000;
    const startLine = Math.max(0, targetLine - chunkSize / 2);
    setCurrentAfterLine(startLine);
  }, [logFileId, viewMode]);

  // Determine current loading state
  const isLoading = useMemo(() => {
    switch (viewMode) {
      case 'content':
        return isLoadingContent;
      case 'tail':
        return isLoadingTail;
      case 'head':
        return isLoadingHead;
      case 'search':
        return isLoadingSearch;
      default:
        return false;
    }
  }, [viewMode, isLoadingContent, isLoadingTail, isLoadingHead, isLoadingSearch]);

  // Determine current error
  const error = useMemo(() => {
    switch (viewMode) {
      case 'content':
        return contentError;
      case 'tail':
        return tailError;
      case 'head':
        return headError;
      case 'search':
        return searchError;
      default:
        return null;
    }
  }, [viewMode, contentError, tailError, headError, searchError]);

  const viewerHeight = useMemo(() => {
    // Use a more reasonable fixed height that works within the flex container
    return 600;
  }, []);

  // Render loading state
  if (isLoadingLines || (isLoading && paginatedState.lines.length === 0)) {
    return (
      <Flex justify="center" align="center" style={{ height: `${viewerHeight}px` }}>
        <LoadingSpinner/>
      </Flex>
    );
  }

  // Render error state
  if (error) {
    return <ErrorDisplay error={error}/>;
  }

  // Render search results
  if (viewMode === 'search') {
    if (!searchData?.data?.results || searchData.data.results.length === 0) {
      return (
        <Flex direction="column" justify="center" align="center" style={{ height: `${viewerHeight}px` }}>
          <Box style={{ marginBottom: '16px', opacity: 0.5 }}>
            <MagnifyingGlassIcon width="48" height="48" style={{ color: '#71717a' }}/>
          </Box>
          <Text style={{ color: '#a1a1aa', fontSize: '14px' }}>
            No search results found for "<Em style={{ color: '#d4d4d8' }}>{debouncedSearchQuery}</Em>"
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

  return (
    <Box style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0 }}>
      {shouldUseVirtualScrolling ? (
        <VirtualLogViewer
          logLines={paginatedState.lines}
          height={viewerHeight}
          onLoadMore={loadMoreContent}
          totalLines={paginatedState.totalLines}
          currentStartLine={paginatedState.currentStartLine}
          hasMoreBefore={paginatedState.hasMoreBefore}
          hasMoreAfter={paginatedState.hasMoreAfter}
          isLoading={isLoading}
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
          isLoading={isLoading}
          hasMoreBefore={paginatedState.hasMoreBefore}
          hasMoreAfter={paginatedState.hasMoreAfter}
          onLoadMore={loadMoreContent}
        />
      )}
    </Box>
  );
};

export default LogViewer;