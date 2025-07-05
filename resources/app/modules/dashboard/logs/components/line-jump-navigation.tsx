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
                                                                        hasMoreAfter = false
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
    <Box style={{
      padding: '8px 12px',
      borderTop: '1px solid #374151',
      backgroundColor: '#1e293b'
    }}>
      <Flex justify="between" align="center" gap="4">
        <Text style={{ color: '#6b7280', fontSize: '12px' }}>
          Line {currentLine.toLocaleString()} of {totalLines.toLocaleString()}
        </Text>

        <Flex gap="2" align="center">
          {/* Load more buttons for content mode */}
          {onLoadMore && (
            <>
              <Button
                size="1"
                variant="soft"
                onClick={() => onLoadMore('before')}
                disabled={isLoading || !hasMoreBefore}
              >
                <ChevronUpIcon width="12" height="12" />
                Load Earlier
              </Button>
              <Button
                size="1"
                variant="soft"
                onClick={() => onLoadMore('after')}
                disabled={isLoading || !hasMoreAfter}
              >
                Load Later
                <ChevronDownIcon width="12" height="12" />
              </Button>
            </>
          )}

          {/* Jump to line controls */}
          <TextField.Root
            placeholder="Jump to line..."
            value={jumpValue}
            onChange={(e) => setJumpValue(e.target.value)}
            onKeyPress={handleKeyPress}
            style={{ width: '120px' }}
            size="1"
          />
          <Button
            size="1"
            variant="soft"
            onClick={handleJump}
            disabled={isLoading || !jumpValue}
          >
            Jump
          </Button>

          {/* Quick navigation */}
          <Button
            size="1"
            variant="soft"
            onClick={() => onJumpToLine(1)}
            disabled={isLoading}
          >
            First
          </Button>
          <Button
            size="1"
            variant="soft"
            onClick={() => onJumpToLine(totalLines)}
            disabled={isLoading}
          >
            Last
          </Button>
        </Flex>
      </Flex>
    </Box>
  );
};