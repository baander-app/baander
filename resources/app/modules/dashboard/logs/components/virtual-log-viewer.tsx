import React, { useCallback } from 'react';
import { FixedSizeList as List } from 'react-window';
import { JsonLogLine } from '@/modules/dashboard/logs/components/json-log-line.tsx';

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
}

// Modern, subtle log level styling
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
                                                                  }) => {
  const itemHeight = 24; // Height of each log line

  const Row = useCallback(({ index, style }: { index: number; style: React.CSSProperties }) => {
    const line = logLines[index];
    if (!line) return null;

    const logStyle = getLogLevelStyle(line.content);

    return (
      <div
        style={{
          ...style,
          display: 'flex',
          alignItems: 'flex-start',
          paddingLeft: '12px',
          paddingRight: '16px',
          paddingTop: '2px',
          paddingBottom: '2px',
          fontFamily: 'var(--font-family-mono)',
          fontSize: '13px',
          lineHeight: '20px',
          cursor: 'text',
          transition: 'background-color 0.15s ease',
          ...logStyle,
        }}
        className="log-line"
        onMouseEnter={(e) => {
          e.currentTarget.style.backgroundColor = '#ffffff06';
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.backgroundColor = logStyle.backgroundColor || 'transparent';
        }}
      >
        <span
          style={{
            width: '80px',
            textAlign: 'right',
            paddingRight: '16px',
            userSelect: 'none',
            color: '#6b7280',
            fontSize: '12px',
            fontWeight: '500',
            flexShrink: 0,
          }}
        >
          {line.line}
        </span>
        <span
          style={{
            flex: 1,
            fontWeight: '400',
            fontSize: 'inherit',
            lineHeight: 'inherit',
            color: 'inherit',
            fontFamily: 'inherit',
            whiteSpace: 'pre-wrap',
            wordBreak: 'break-word',
            overflow: 'hidden',
          }}
        >
          <JsonLogLine line={line.line} content={line.content} logStyle={logStyle}/>
        </span>
      </div>
    );
  }, [logLines]);

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
      <List
        height={height - 2} // Subtract border height
        itemCount={logLines.length}
        itemSize={itemHeight}
        width="100%"
        overscanCount={5}
        style={{
          outline: 'none',
        }}
      >
        {Row}
      </List>
    </div>
  );
};