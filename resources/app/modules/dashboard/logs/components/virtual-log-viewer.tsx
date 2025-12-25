import React, { useCallback, useRef, useEffect } from 'react';
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
  if (upperContent.includes('TRACE')) {
    return {
      color: '#c084fc',
      borderLeft: '3px solid #c084fc',
      backgroundColor: '#c084fc0a',
    };
  }
  return {
    color: '#d1d5db',
    borderLeft: '3px solid transparent',
    backgroundColor: 'transparent',
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

  const virtualizer = useVirtualizer({
    count: logLines.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 32,
    overscan: 10,
  });

  // Reset loading ref when isLoading prop becomes false
  useEffect(() => {
    if (!isLoading && isLoadingRef.current) {
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

      if (isLoadingRef.current || isLoading) return;

      // Load more before (top)
      if (scrollTop < 50 && hasMoreBefore) {
        isLoadingRef.current = true;
        onLoadMore('before');
        return;
      }

      // Load more after (bottom)
      if (distanceFromBottom < 50 && hasMoreAfter) {
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
    if (isLoadingRef.current || isLoading || !onLoadMore) return;

    const virtualItems = virtualizer.getVirtualItems();
    if (virtualItems.length === 0) return;

    const firstItem = virtualItems[0];
    const lastItem = virtualItems[virtualItems.length - 1];

    // Load more before when first visible item is near the top
    if (firstItem.index <= 3 && hasMoreBefore) {
      isLoadingRef.current = true;
      onLoadMore('before');
      return;
    }

    // Load more after when last visible item is near the bottom
    if (lastItem.index >= logLines.length - 4 && hasMoreAfter) {
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
      </div>
    );
  }, [logLines, virtualizer]);

  return (
    <div
      style={{
        height: height,
        width: '100%',
        backgroundColor: '#0a0a0a',
        borderRadius: '8px',
        border: '1px solid #27272a',
        boxShadow: '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
        overflow: 'hidden',
        position: 'relative',
      }}
    >
      {/* Loading indicator */}
      {isLoading && (
        <div
          style={{
            position: 'absolute',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            backgroundColor: '#18181b',
            border: '1px solid #27272a',
            borderRadius: '8px',
            padding: '12px 16px',
            fontSize: '13px',
            color: '#a1a1aa',
            zIndex: 10,
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            boxShadow: '0 4px 12px rgb(0 0 0 / 0.3)',
          }}
        >
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            style={{ animation: 'spin 1s linear infinite' }}
          >
            <circle
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              strokeWidth="3"
              strokeOpacity="0.3"
            />
            <path
              d="M12 2C17.5228 2 22 6.47715 22 12"
              stroke="currentColor"
              strokeWidth="3"
              strokeLinecap="round"
            />
          </svg>
          <style>{`@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`}</style>
          Loading logs...
        </div>
      )}

      {/* Virtual scroll container */}
      <div
        ref={parentRef}
        style={{
          height: '100%',
          width: '100%',
          overflow: 'auto',
          padding: '12px',
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