import React, { useState } from 'react';
import { Box, Flex, TextField, Button, Text } from '@radix-ui/themes';
import { ChevronUpIcon, ChevronDownIcon } from '@radix-ui/react-icons';

interface LineJumpNavigationProps {
  totalLines: number;
  currentLine: number;
  onJumpToLine: (line: number) => void;
  onLoadMore?: (direction: 'before' | 'after') => void;
  isLoading?: boolean;
  hasMoreBefore?: boolean;
  hasMoreAfter?: boolean;
}

export const LineJumpNavigation: React.FC<LineJumpNavigationProps> = ({
  totalLines,
  currentLine,
  onJumpToLine,
  onLoadMore,
  isLoading = false,
  hasMoreBefore = false,
  hasMoreAfter = false,
}) => {
  const [jumpValue, setJumpValue] = useState('');

  const handleJump = () => {
    const lineNumber = parseInt(jumpValue, 10);
    if (lineNumber > 0 && lineNumber <= totalLines) {
      onJumpToLine(lineNumber);
      setJumpValue('');
    }
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') {
      handleJump();
    }
  };

  return (
    <Box
      style={{
        padding: '12px 16px',
        borderTop: '1px solid #27272a',
        backgroundColor: '#09090b',
        borderRadius: '0 0 8px 8px',
      }}
    >
      <Flex justify="between" align="center" gap="4">
        <Text style={{ color: '#a1a1aa', fontSize: '13px', fontWeight: '500' }}>
          {currentLine.toLocaleString()} / {totalLines.toLocaleString()} lines
        </Text>

        <Flex gap="2" align="center">
          {/* Load more buttons for content mode */}
          {onLoadMore && (
            <>
              <Button
                size="1"
                variant="outline"
                onClick={() => onLoadMore('before')}
                disabled={isLoading || !hasMoreBefore}
                style={{
                  borderColor: '#3f3f46',
                  color: hasMoreBefore ? '#fafafa' : '#71717a',
                }}
              >
                <ChevronUpIcon width="12" height="12" />
                Earlier
              </Button>
              <Button
                size="1"
                variant="outline"
                onClick={() => onLoadMore('after')}
                disabled={isLoading || !hasMoreAfter}
                style={{
                  borderColor: '#3f3f46',
                  color: hasMoreAfter ? '#fafafa' : '#71717a',
                }}
              >
                Later
                <ChevronDownIcon width="12" height="12" />
              </Button>
            </>
          )}

          {/* Jump to line controls */}
          <TextField.Root
            placeholder="Line..."
            value={jumpValue}
            onChange={(e) => setJumpValue(e.target.value)}
            onKeyPress={handleKeyPress}
            style={{ width: '80px' }}
            size="1"
          />
          <Button
            size="1"
            variant="outline"
            onClick={handleJump}
            disabled={isLoading || !jumpValue}
            style={{ borderColor: '#3f3f46' }}
          >
            Go
          </Button>

          {/* Quick navigation */}
          <Button
            size="1"
            variant="outline"
            onClick={() => onJumpToLine(1)}
            disabled={isLoading}
            style={{ borderColor: '#3f3f46' }}
          >
            First
          </Button>
          <Button
            size="1"
            variant="outline"
            onClick={() => onJumpToLine(totalLines)}
            disabled={isLoading}
            style={{ borderColor: '#3f3f46' }}
          >
            Last
          </Button>
        </Flex>
      </Flex>
    </Box>
  );
};