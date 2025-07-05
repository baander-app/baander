import React, { useState } from 'react';
import { useLogsServiceGetApiLogsByLogFileStats } from '@/api-client/queries';
import { LoadingSpinner } from '@/modules/common/LoadingSpinner.tsx';
import { ErrorDisplay } from '@/modules/common/ErrorDisplay.tsx';
import { 
  Box, 
  Flex, 
  Grid, 
  Text, 
  Heading, 
  Card, 
  Button, 
  Table,
  Progress,
  IconButton,
} from '@radix-ui/themes';
import { DownloadIcon, ChevronDownIcon, ChevronUpIcon } from '@radix-ui/react-icons';

interface LogStatsProps {
  logFileId: string;
}

export const LogStats: React.FC<LogStatsProps> = ({ logFileId }) => {
  const [isCollapsed, setIsCollapsed] = useState(true);

  const toggleCollapsed = () => {
    setIsCollapsed(!isCollapsed);
  };

  const {
    data: statsData,
    isLoading,
    error,
  } = useLogsServiceGetApiLogsByLogFileStats(
    { logFile: logFileId },
    undefined,
    { enabled: !!logFileId }
  );

  if (isLoading) {
    return (
      <Box p="4" style={{ backgroundColor: 'var(--gray-2)', borderTop: '1px solid var(--gray-4)' }}>
        <LoadingSpinner size="small" />
      </Box>
    );
  }

  if (error) {
    return (
      <Box p="4" style={{ backgroundColor: 'var(--gray-2)', borderTop: '1px solid var(--gray-4)' }}>
        <ErrorDisplay error={error} title="Failed to load log statistics" />
      </Box>
    );
  }

  if (!statsData) {
    return null;
  }

  const { fileInfo, performance, logLevels } = statsData.data;

  return (
    <Box className="log-stats" style={{ backgroundColor: 'var(--gray-2)', borderTop: '1px solid var(--gray-4)' }}>
      <Flex justify="between" align="center" p="2" style={{ borderBottom: isCollapsed ? 'none' : '1px solid var(--gray-4)' }}>
        <Flex align="center" gap="2">
          <IconButton 
            variant="ghost" 
            onClick={toggleCollapsed}
            aria-label={isCollapsed ? "Expand stats" : "Collapse stats"}
          >
            {isCollapsed ? <ChevronDownIcon /> : <ChevronUpIcon />}
          </IconButton>
          <Text weight="medium">Log Statistics</Text>
          {isCollapsed && (
            <Text size="1" color="gray">
              {fileInfo.lines.toLocaleString()} lines, {fileInfo.sizeMb} MB
            </Text>
          )}
        </Flex>
        <Button asChild size="1" color="blue">
          <a href={`/api/logs/${logFileId}/download`} download>
            <DownloadIcon />
            Download
          </a>
        </Button>
      </Flex>

      {!isCollapsed && (
        <Box p="4">
          <Grid columns={{ initial: '1', md: '3' }} gap="4">
            {/* File Information */}
            <Card>
              <Heading size="3" mb="2">File Information</Heading>
              <Table.Root>
                <Table.Body>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Size:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{fileInfo.sizeMb}</Text></Table.Cell>
                  </Table.Row>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Lines:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{fileInfo.lines.toLocaleString()}</Text></Table.Cell>
                  </Table.Row>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Modified:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{fileInfo.size}</Text></Table.Cell>
                  </Table.Row>
                </Table.Body>
              </Table.Root>
            </Card>

            {/* Log Levels */}
            <Card>
              <Heading size="3" mb="2">Log Levels</Heading>
              <Box>
                <LogLevelBar label="Error" count={logLevels.error} color="red" />
                <LogLevelBar label="Warning" count={logLevels.warning} color="yellow" />
                <LogLevelBar label="Info" count={logLevels.info} color="blue" />
                <LogLevelBar label="Debug" count={logLevels.debug} color="gray" />
              </Box>
            </Card>

            {/* Performance */}
            <Card>
              <Heading size="3" mb="2">Performance</Heading>
              <Table.Root>
                <Table.Body>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Large File:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{performance.isLargeFile ? 'Yes' : 'No'}</Text></Table.Cell>
                  </Table.Row>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Threading:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{performance.shouldUseThreading ? 'Recommended' : 'Not needed'}</Text></Table.Cell>
                  </Table.Row>
                  <Table.Row>
                    <Table.Cell><Text color="gray" size="2">Optimal Threads:</Text></Table.Cell>
                    <Table.Cell><Text size="2">{performance.optimalThreads}</Text></Table.Cell>
                  </Table.Row>
                </Table.Body>
              </Table.Root>
            </Card>
          </Grid>
        </Box>
      )}
    </Box>
  );
};

// Helper component for log level bars
interface LogLevelBarProps {
  label: string;
  count: string;
  color: 'red' | 'yellow' | 'blue' | 'gray';
}

const LogLevelBar: React.FC<LogLevelBarProps> = ({ label, count, color }) => {
  // Calculate percentage (assuming max is 100 for simplicity)
  const percentage = Math.min(100, Math.max(0, Number.parseInt(count) / 10));

  return (
    <Box mb="2">
      <Flex justify="between" mb="1">
        <Text color="gray" size="2">{label}</Text>
        <Text color="gray" size="2">{count}</Text>
      </Flex>
      <Progress value={percentage} color={color} size="1" />
    </Box>
  );
};

export default LogStats;
