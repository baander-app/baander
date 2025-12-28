import React, { useState, useCallback } from 'react';
import { ChevronRightIcon, ChevronDownIcon } from '@radix-ui/react-icons';
import { Box, Text } from '@radix-ui/themes';

interface JsonLogLineProps {
  line: number;
  content: string;
  logStyle: any;
  onClick?: () => void;
}

const findJsonInLine = (content: string): { prefix: string; jsonPart: string; suffix: string } | null => {
  // More flexible approach: find balanced braces and brackets
  const findBalancedJson = (str: string, startChar: string, endChar: string, startIndex: number): string | null => {
    let depth = 0;
    let start = startIndex;
    let inString = false;
    let escaped = false;

    for (let i = startIndex; i < str.length; i++) {
      const char = str[i];

      if (escaped) {
        escaped = false;
        continue;
      }

      if (char === '\\') {
        escaped = true;
        continue;
      }

      if (char === '"') {
        inString = !inString;
        continue;
      }

      if (inString) continue;

      if (char === startChar) {
        if (depth === 0) start = i;
        depth++;
      } else if (char === endChar) {
        depth--;
        if (depth === 0) {
          return str.substring(start, i + 1);
        }
      }
    }

    return null;
  };

  // Look for JSON objects starting with {
  for (let i = 0; i < content.length; i++) {
    if (content[i] === '{') {
      const jsonCandidate = findBalancedJson(content, '{', '}', i);
      if (jsonCandidate) {
        try {
          JSON.parse(jsonCandidate);

          const prefix = content.substring(0, i).trim();
          const suffix = content.substring(i + jsonCandidate.length).trim();

          return {
            prefix,
            jsonPart: jsonCandidate,
            suffix
          };
        } catch {
          // Not valid JSON, continue searching
        }
      }
    }
  }

  // Look for JSON arrays starting with [
  for (let i = 0; i < content.length; i++) {
    if (content[i] === '[') {
      const jsonCandidate = findBalancedJson(content, '[', ']', i);
      if (jsonCandidate) {
        try {
          JSON.parse(jsonCandidate);

          const prefix = content.substring(0, i).trim();
          const suffix = content.substring(i + jsonCandidate.length).trim();

          return {
            prefix,
            jsonPart: jsonCandidate,
            suffix
          };
        } catch {
          // Not valid JSON, continue searching
        }
      }
    }
  }

  return null;
};

const parseJsonSafely = (jsonString: string): any => {
  try {
    return JSON.parse(jsonString);
  } catch {
    return null;
  }
};

export const JsonLogLine: React.FC<JsonLogLineProps> = ({
                                                          line,
                                                          content,
                                                          logStyle,
                                                          onClick
                                                        }) => {
  const [isExpanded, setIsExpanded] = useState(false);
  const [jsonInfo, setJsonInfo] = useState<{ prefix: string; jsonPart: string; suffix: string } | null>(null);
  const [parsedJson, setParsedJson] = useState<any>(null);
  const [isJsonChecked, setIsJsonChecked] = useState(false);

  // Handle click - this is when we check for JSON and parse it
  const handleClick = useCallback(() => {
    if (!isJsonChecked) {
      // First click - check for JSON
      const foundJson = findJsonInLine(content);
      setJsonInfo(foundJson);
      setIsJsonChecked(true);

      if (foundJson) {
        // Parse the JSON
        const parsed = parseJsonSafely(foundJson.jsonPart);
        setParsedJson(parsed);
        setIsExpanded(true);
      }
    } else if (jsonInfo) {
      // Subsequent clicks - just toggle expansion
      setIsExpanded(!isExpanded);
    }

    onClick?.();
  }, [content, isJsonChecked, jsonInfo, isExpanded, onClick]);

  return (
    <div
      style={{
        ...logStyle,
        cursor: 'pointer',
        transition: 'all 0.15s ease',
      }}
      onClick={handleClick}
    >
      <div
        style={{
          display: 'flex',
          alignItems: 'flex-start',
          paddingLeft: '12px',
          paddingRight: '16px',
          paddingTop: '4px',
          paddingBottom: '4px',
          fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace',
          fontSize: '13px',
          lineHeight: '1.5',
        }}
      >
        {/* Line number */}
        <span
          style={{
            width: '80px',
            textAlign: 'right',
            paddingRight: '16px',
            userSelect: 'none',
            color: '#71717a',
            fontSize: '12px',
            fontWeight: '500',
            flexShrink: 0,
          }}
        >
          {line}
        </span>

        {/* Expand/collapse icon for JSON lines - only show after JSON is detected */}
        {jsonInfo && (
          <div
            style={{
              width: '20px',
              height: '20px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              marginRight: '8px',
              flexShrink: 0,
            }}
          >
            {isExpanded ? (
              <ChevronDownIcon width="14" height="14" style={{ color: '#a1a1aa' }} />
            ) : (
               <ChevronRightIcon width="14" height="14" style={{ color: '#a1a1aa' }} />
             )}
          </div>
        )}

        {/* Content */}
        <div style={{ flex: 1, minWidth: 0, overflowWrap: 'break-word' }}>
          {/* Regular log line or collapsed JSON */}
          {!isExpanded && (
            <Text
              style={{
                fontWeight: '400',
                fontSize: 'inherit',
                lineHeight: 'inherit',
                color: 'inherit',
                fontFamily: 'inherit',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
              }}
            >
              {jsonInfo ? (
                <>
                  {jsonInfo.prefix && (
                    <Text style={{ color: '#e2e8f0' }}>{jsonInfo.prefix}</Text>
                  )}
                  {jsonInfo.prefix && ' '}
                  <Text style={{
                    color: '#fbbf24',
                    backgroundColor: '#fbbf2415',
                    padding: '1px 4px',
                    borderRadius: '3px',
                    border: '1px solid #fbbf2430'
                  }}>
                    {jsonInfo.jsonPart}
                  </Text>
                  {jsonInfo.suffix && (
                    <>
                      {' '}
                      <Text as="span" style={{ color: '#e2e8f0' }}>{jsonInfo.suffix}</Text>
                    </>
                  )}
                </>
              ) : (
                 content
               )}
            </Text>
          )}

          {/* Expanded JSON view */}
          {isExpanded && parsedJson && jsonInfo && (
            <Text style={{ marginTop: '10px' }}>
              {/* Message prefix */}
              {jsonInfo.prefix && (
                <Text
                  style={{
                    marginBottom: '10px',
                    color: '#e4e4e7',
                    fontSize: '14px',
                    fontFamily: 'inherit',
                    lineHeight: '22px',
                  }}
                >
                  {jsonInfo.prefix}
                </Text>
              )}

              {/* JSON viewer */}
              <Text
                style={{
                  backgroundColor: '#18181b',
                  border: '1px solid #27272a',
                  borderRadius: '6px',
                  padding: '12px',
                  marginTop: '4px',
                  marginBottom: '4px',
                  overflow: 'auto',
                  maxHeight: '400px',
                }}
              >
                {/*<ReactJsonView*/}
                {/*  src={parsedJson}*/}
                {/*  theme="monokai"*/}
                {/*  style={{*/}
                {/*    backgroundColor: 'transparent',*/}
                {/*    fontSize: '13px',*/}
                {/*    fontFamily: 'var(--font-family-mono)',*/}
                {/*  }}*/}
                {/*  displayObjectSize={false}*/}
                {/*  displayDataTypes={false}*/}
                {/*  enableClipboard={true}*/}
                {/*  collapsed={false}*/}
                {/*  collapseStringsAfterLength={100}*/}
                {/*  indentWidth={2}*/}
                {/*  iconStyle="triangle"*/}
                {/*  quotesOnKeys={false}*/}
                {/*  sortKeys={false}*/}
                {/*/>*/}
              </Text>

              {/* Message suffix */}
              {jsonInfo.suffix && (
                <Box
                  style={{
                    marginTop: '10px',
                    color: '#e4e4e7',
                    fontSize: '14px',
                    fontFamily: 'inherit',
                    lineHeight: '22px',
                  }}
                >
                  {jsonInfo.suffix}
                </Box>
              )}
            </Text>
          )}

          {/* Show error if JSON parsing failed */}
          {isExpanded && isJsonChecked && jsonInfo && !parsedJson && (
            <Box
              style={{
                marginTop: '8px',
                padding: '8px',
                backgroundColor: '#ff6b6b20',
                border: '1px solid #ff6b6b40',
                borderRadius: '4px',
                color: '#ff6b6b',
                fontSize: '12px',
              }}
            >
              Invalid JSON format
            </Box>
          )}
        </div>
      </div>
    </div>
  );
};