import { useState, useEffect, useCallback } from 'react';
import {
  Button,
  Card,
  Container,
  Flex,
  Heading,
  Text,
  Callout,
} from '@radix-ui/themes';
import { Iconify } from '@/app/ui/icons/iconify.tsx';
import { useLibrariesIndex } from '@/app/libs/api-client/gen/endpoints/library/library.ts';
import { LibrarySelector, type Library } from './library-selector.tsx';
import { SyncOptions } from './sync-options.tsx';
import { SyncProgress } from './sync-progress.tsx';
import { SyncResults } from './sync-results.tsx';
import { AXIOS_INSTANCE } from '@/app/libs/api-client/axios-instance';
import type { LibraryResource } from '@/app/libs/api-client/gen/models';

interface SyncResponse {
  message: string;
  jobs_queued: number;
  sync_details: {
    albums: number;
    songs: number;
    artists: number;
    library_id: number | null;
    include_songs: boolean;
    include_artists: boolean;
  };
}

interface QueueMetrics {
  data: Array<{
    id: string;
    name: string;
    status: string;
    queue: string;
    progress: number | null;
    started_at: string | null;
    finished_at: string | null;
    attempt: number;
  }>;
  meta: {
    total: number;
  };
}

export interface SyncOptionsConfig {
  forceUpdate: boolean;
  batchSize: number;
  includeSongs: boolean;
  includeArtists: boolean;
}

export function SyncTab() {
  const [selectedLibraryIds, setSelectedLibraryIds] = useState<number[]>([]);
  const [syncOptions, setSyncOptions] = useState<SyncOptionsConfig>({
    forceUpdate: false,
    batchSize: 10,
    includeSongs: true,
    includeArtists: true,
  });
  const [syncStarted, setSyncStarted] = useState(false);
  const [isStartingSync, setIsStartingSync] = useState(false);
  const [syncResponse, setSyncResponse] = useState<SyncResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [jobCounts, setJobCounts] = useState({
    queued: 0,
    running: 0,
    completed: 0,
  });

  const { data: librariesData, isLoading: librariesLoading } = useLibrariesIndex(
    { limit: 100 },
    {}
  );

  const libraries: Library[] = (librariesData?.data ?? []).map((lib: LibraryResource) => ({
    id: lib.id,
    name: lib.name,
    path: lib.path,
    type: lib.type,
  }));

  const startSync = useCallback(async () => {
    if (selectedLibraryIds.length === 0) {
      setError('Please select at least one library');
      return;
    }

    setIsStartingSync(true);
    setError(null);

    try {
      const response = await AXIOS_INSTANCE.post<SyncResponse>('/api/metadata/sync', {
        library_id: selectedLibraryIds[0],
        include_songs: syncOptions.includeSongs,
        include_artists: syncOptions.includeArtists,
        force_update: syncOptions.forceUpdate,
        batch_size: syncOptions.batchSize,
      });

      setSyncResponse(response.data);
      setSyncStarted(true);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to start sync. Please try again.');
      setSyncStarted(false);
    } finally {
      setIsStartingSync(false);
    }
  }, [selectedLibraryIds, syncOptions]);

  useEffect(() => {
    if (!syncStarted) return;

    const pollInterval = setInterval(async () => {
      try {
        const response = await AXIOS_INSTANCE.get<QueueMetrics>('/api/queue-metrics', {
          params: {
            'name[]': ['SyncAlbumJob', 'SyncArtistJob'],
          },
        });

        const jobs = response.data.data;
        const queued = jobs.filter((j) => j.status === 'queued').length;
        const running = jobs.filter((j) => j.status === 'running').length;
        const completed = jobs.filter((j) => j.status === 'succeeded').length;

        setJobCounts({ queued, running, completed });

        if (queued === 0 && running === 0) {
          clearInterval(pollInterval);
        }
      } catch (err) {
        console.error('Failed to fetch queue metrics:', err);
      }
    }, 2000);

    return () => clearInterval(pollInterval);
  }, [syncStarted]);

  const canStartSync = selectedLibraryIds.length > 0 && !isStartingSync;
  const isComplete = syncStarted && jobCounts.queued === 0 && jobCounts.running === 0;

  return (
    <Container mt="3">
      <Heading mb="4">Sync Metadata</Heading>

      {!syncStarted ? (
        <>
          <Card mb="4">
            <Flex direction="column" gap="4">
              <LibrarySelector
                libraries={libraries}
                selectedIds={selectedLibraryIds}
                onSelect={setSelectedLibraryIds}
                loading={librariesLoading}
              />

              <SyncOptions
                options={syncOptions}
                onChange={setSyncOptions}
              />

              {error && (
                <Callout.Root color="red">
                  <Callout.Icon>
                    <Iconify icon="eva:alert-circle-outline" />
                  </Callout.Icon>
                  <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
              )}

              <Flex justify="end">
                <Button
                  onClick={startSync}
                  disabled={!canStartSync}
                  size="3"
                >
                  {isStartingSync ? 'Starting Sync...' : 'Start Sync'}
                </Button>
              </Flex>
            </Flex>
          </Card>

          {syncResponse && (
            <Card>
              <Flex direction="column" gap="2">
                <Heading size="4">Sync Details</Heading>
                <Text>Jobs queued: {syncResponse.jobs_queued}</Text>
                <Text>Library ID: {syncResponse.sync_details.library_id}</Text>
                <Text>Include songs: {syncResponse.sync_details.include_songs ? 'Yes' : 'No'}</Text>
                <Text>Include artists: {syncResponse.sync_details.include_artists ? 'Yes' : 'No'}</Text>
              </Flex>
            </Card>
          )}
        </>
      ) : (
        <>
          <SyncProgress
            queued={jobCounts.queued}
            running={jobCounts.running}
            completed={jobCounts.completed}
          />

          {isComplete && (
            <SyncResults
              jobsCompleted={jobCounts.completed}
              onReset={() => {
                setSyncStarted(false);
                setSyncResponse(null);
                setJobCounts({ queued: 0, running: 0, completed: 0 });
              }}
            />
          )}
        </>
      )}
    </Container>
  );
}
