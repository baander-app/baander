import React, { useCallback, useRef, useEffect, useState } from 'react';
import { useVirtualizer } from '@tanstack/react-virtual';
import { JsonLogLine } from './json-log-line.tsx';

interface LogLine {
  line: number;
  content: string;
}

interface VirtualLogViewerProps {
  logLines: LogLine[];
  height: number;
  onLoadMore?: (direction: 'before' | 'after') => void;
  totalLines?: number;
  currentStartLine?: number;
  hasMoreBefore?: boolean;
  hasMoreAfter?: boolean;
  isLoading?: boolean;
}

const getLogLevelStyle = (content: string) => {
  const upperContent = content.toUpperCase();
  if (upperContent.includes('ERROR') || upperContent.includes('FATAL')) {
    return {
      color: '#ff6b6b',
      borderLeft: '3px solid #ff6b6b20',
      backgroundColor: '#ff6b6b08',
    };
  }
  if (upperContent.includes('WARN')) {
    return {
      color: '#ffa726',
      borderLeft: '3px solid #ffa72620',
      backgroundColor: '#ffa72608',
    };
  }
  if (upperContent.includes('INFO')) {
    return {
      color: '#42a5f5',
      borderLeft: '3px solid #42a5f520',
      backgroundColor: '#42a5f508',
    };
  }
  if (upperContent.includes('DEBUG')) {
    return {
      color: '#9e9e9e',
      borderLeft: '3px solid transparent',
    };
  }
  if (upperContent.includes('TRACE')) {
    return {
      color: '#ab47bc',
      borderLeft: '3px solid #ab47bc20',
      backgroundColor: '#ab47bc08',
    };
  }
  return {
    color: '#e0e0e0',
    borderLeft: '3px solid transparent',
  };
};

export const VirtualLogViewer: React.FC<VirtualLogViewerProps> = ({
                                                                    logLines,
                                                                    height,
                                                                    onLoadMore,
                                                                    hasMoreBefore = false,
                                                                    hasMoreAfter = false,
                                                                    isLoading = false,
                                                                  }) => {
  const parentRef = useRef<HTMLDivElement>(null);
  const isLoadingRef = useRef(false);
  const [debugInfo, setDebugInfo] = useState<any>({});

  const virtualizer = useVirtualizer({
    count: logLines.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 24,
    overscan: 5,
  });

  // Reset loading ref when isLoading prop becomes false
  useEffect(() => {
    if (!isLoading && isLoadingRef.current) {
      console.log('🔄 Resetting isLoadingRef: isLoading became false');
      isLoadingRef.current = false;
    }
  }, [isLoading]);

  // Handle scroll-based infinite loading
  useEffect(() => {
    const scrollElement = parentRef.current;
    if (!scrollElement || !onLoadMore) return;

    const handleScroll = () => {
      const { scrollTop, scrollHeight, clientHeight } = scrollElement;
      const distanceFromBottom = scrollHeight - (scrollTop + clientHeight);

      setDebugInfo({ scrollTop, scrollHeight, clientHeight, distanceFromBottom });

      if (isLoadingRef.current || isLoading) return;

      // Load more before (top)
      if (scrollTop < 100 && hasMoreBefore) {
        console.log('🔥 SCROLL TRIGGER - loading before');
        isLoadingRef.current = true;
        onLoadMore('before');
        return;
      }

      // Load more after (bottom)
      if (distanceFromBottom < 100 && hasMoreAfter) {
        console.log('🔥 SCROLL TRIGGER - loading after');
        isLoadingRef.current = true;
        onLoadMore('after');
        return;
      }
    };

    scrollElement.addEventListener('scroll', handleScroll, { passive: true });
    return () => scrollElement.removeEventListener('scroll', handleScroll);
  }, [onLoadMore, hasMoreBefore, hasMoreAfter, isLoading]);

  // Handle virtual items-based infinite loading
  useEffect(() => {
    if (isLoadingRef.current || isLoading || !onLoadMore || logLines.length < 10) return;

    const virtualItems = virtualizer.getVirtualItems();
    if (virtualItems.length === 0) return;

    const firstItem = virtualItems[0];
    const lastItem = virtualItems[virtualItems.length - 1];

    // Load more before when first visible item is within first 3 items
    if (firstItem.index <= 2 && hasMoreBefore) {
      console.log('🔥 VIRTUAL TRIGGER - loading before, first index:', firstItem.index);
      isLoadingRef.current = true;
      onLoadMore('before');
      return;
    }

    // Load more after when last visible item is within last 3 items
    if (lastItem.index >= logLines.length - 3 && hasMoreAfter) {
      console.log('🔥 VIRTUAL TRIGGER - loading after, last index:', lastItem.index);
      isLoadingRef.current = true;
      onLoadMore('after');
      return;
    }
  }, [virtualizer.getVirtualItems(), hasMoreBefore, hasMoreAfter, onLoadMore, logLines.length, isLoading]);

  const renderLogLine = useCallback((virtualItem: any) => {
    const line = logLines[virtualItem.index];
    if (!line) return null;

    const logStyle = getLogLevelStyle(line.content);

    return (
      <div
        key={virtualItem.key}
        data-index={virtualItem.index}
        ref={(el) => virtualizer.measureElement(el)}
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          transform: `translateY(${virtualItem.start}px)`,
        }}
      >
        <div
          style={{
            minHeight: '24px',
            borderRadius: '4px',
            transition: 'background-color 0.15s ease',
            ...logStyle,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.backgroundColor = '#ffffff06';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.backgroundColor = logStyle.backgroundColor || 'transparent';
          }}
        >
          <JsonLogLine
            line={line.line}
            content={line.content}
            logStyle={logStyle}
          />
        </div>
      </div>
    );
  }, [logLines, virtualizer]);

  return (
    <div
      style={{
        height: height,
        width: '100%',
        backgroundColor: '#0f172a',
        borderRadius: '12px',
        border: '1px solid #1e293b',
        boxShadow: '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
        overflow: 'hidden',
        position: 'relative',
      }}
    >
      {/* Debug info */}
      <div
        style={{
          position: 'absolute',
          top: '8px',
          right: '8px',
          backgroundColor: '#1e293b',
          border: '1px solid #334155',
          borderRadius: '4px',
          padding: '6px 8px',
          fontSize: '9px',
          color: '#64748b',
          zIndex: 10,
          opacity: 0.9,
          fontFamily: 'monospace',
          lineHeight: '1.2',
        }}
      >
        <div>Lines: {logLines.length}</div>
        <div>More: {hasMoreBefore ? '↑' : ''}{hasMoreAfter ? '↓' : ''}</div>
        <div>Loading: {isLoading ? 'Y' : 'N'}</div>
        <div>LoadRef: {isLoadingRef.current ? 'Y' : 'N'}</div>
        {debugInfo.scrollTop !== undefined && (
          <>
            <div>Top: {Math.round(debugInfo.scrollTop)}</div>
            <div>Bot: {Math.round(debugInfo.distanceFromBottom || 0)}</div>
          </>
        )}
      </div>

      {/* Test buttons */}
      <div
        style={{
          position: 'absolute',
          top: '8px',
          left: '8px',
          zIndex: 10,
          display: 'flex',
          gap: '4px',
        }}
      >
        <button
          onClick={() => {
            if (onLoadMore && hasMoreBefore) {
              isLoadingRef.current = true;
              onLoadMore('before');
            }
          }}
          style={{
            padding: '4px 8px',
            fontSize: '10px',
            backgroundColor: hasMoreBefore ? '#dc2626' : '#374151',
            color: '#e5e7eb',
            border: '1px solid #6b7280',
            borderRadius: '4px',
            cursor: 'pointer',
          }}
          disabled={!hasMoreBefore || !onLoadMore}
        >
          ↑ Before
        </button>
        <button
          onClick={() => {
            if (onLoadMore && hasMoreAfter) {
              isLoadingRef.current = true;
              onLoadMore('after');
            }
          }}
          style={{
            padding: '4px 8px',
            fontSize: '10px',
            backgroundColor: hasMoreAfter ? '#dc2626' : '#374151',
            color: '#e5e7eb',
            border: '1px solid #6b7280',
            borderRadius: '4px',
            cursor: 'pointer',
          }}
          disabled={!hasMoreAfter || !onLoadMore}
        >
          ↓ After
        </button>
        <button
          onClick={() => {
            isLoadingRef.current = false;
          }}
          style={{
            padding: '4px 8px',
            fontSize: '10px',
            backgroundColor: '#059669',
            color: '#e5e7eb',
            border: '1px solid #6b7280',
            borderRadius: '4px',
            cursor: 'pointer',
          }}
        >
          Reset
        </button>
      </div>

      {/* Loading indicators */}
      {isLoading && (
        <div
          style={{
            position: 'absolute',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            backgroundColor: '#1e293b',
            border: '1px solid #334155',
            borderRadius: '6px',
            padding: '8px 12px',
            fontSize: '12px',
            color: '#64748b',
            zIndex: 10,
          }}
        >
          Loading...
        </div>
      )}

      {/* Virtual scroll container */}
      <div
        ref={parentRef}
        style={{
          height: '100%',
          width: '100%',
          overflow: 'auto',
          padding: '8px',
        }}
      >
        <div
          style={{
            height: virtualizer.getTotalSize(),
            width: '100%',
            position: 'relative',
          }}
        >
          {virtualizer.getVirtualItems().map(renderLogLine)}
        </div>
      </div>
    </div>
  );
};