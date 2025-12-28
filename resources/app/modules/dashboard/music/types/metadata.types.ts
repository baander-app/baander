/**
 * Shared TypeScript types for Music Metadata Sync and Browse features
 */

export interface ArtistMetadata {
  id: string;
  name: string;
  sortName?: string;
  disambiguation?: string;
  type?: string;
  gender?: string;
  country?: string;
  area?: {
    name: string;
    iso_3166_1_codes?: string[];
  };
  begin_area?: {
    name: string;
  };
  life_span?: {
    begin?: string;
    end?: string;
    ended?: boolean;
  };
  ipi?: string;
  isni?: string;
  cover_art?: string;
}

export interface ReleaseGroupMetadata {
  id: string;
  title: string;
  primary_type?: string;
  secondary_types?: string[];
  first_release_date?: string;
  artist_credit?: {
    name: string;
    artist: {
      id: string;
      name: string;
    };
  }[];
  cover_art?: string;
}

export interface ReleaseMetadata {
  id: string;
  title: string;
  status?: string;
  quality?: string;
  format?: string;
  country?: string;
  date?: string;
  release_events?: {
    date?: string;
    area?: {
      name: string;
      iso_3166_1_codes?: string[];
    };
  }[];
  barcode?: string;
  cover_art?: string;
  artist_credit?: {
    name: string;
    artist: {
      id: string;
      name: string;
    };
  }[];
  media?: {
    format: string;
    track_count: number;
    position: number;
    tracks?: TrackMetadata[];
  }[];
}

export interface TrackMetadata {
  id: string;
  number: number;
  title: string;
  length?: number;
  artist_credit?: {
    name: string;
    artist: {
      id: string;
      name: string;
    };
  }[];
}

export interface SearchResults {
  artists: ArtistMetadata[];
  release_groups: ReleaseGroupMetadata[];
  releases: ReleaseMetadata[];
}

export interface MetadataPreview {
  type: 'artist' | 'release' | 'release_group';
  metadata: ArtistMetadata | ReleaseGroupMetadata | ReleaseMetadata;
  confidence: number;
  source: 'musicbrainz' | 'discogs' | 'manual';
}

export interface SyncConfig {
  artists: boolean;
  albums: boolean;
  songs: boolean;
  skipExisting: boolean;
  overwrite: {
    artistMetadata: boolean;
    albumMetadata: boolean;
    songMetadata: boolean;
    coverArt: boolean;
  };
  rateLimiting: {
    enabled: boolean;
    requestsPerSecond: number;
  };
}

export interface SyncStatus {
  isRunning: boolean;
  progress: {
    current: number;
    total: number;
    percentage: number;
  };
  stage: 'idle' | 'scanning' | 'matching' | 'fetching' | 'syncing' | 'complete' | 'error';
  errors: {
    code: string;
    message: string;
    details?: any;
  }[];
  stats: {
    artistsProcessed: number;
    albumsProcessed: number;
    songsProcessed: number;
    errors: number;
  };
}

export interface QueueMetrics {
  total_jobs: number;
  pending_jobs: number;
  processing_jobs: number;
  completed_jobs: number;
  failed_jobs: number;
  jobs?: QueueJob[];
}

export interface QueueJob {
  id: string;
  queue: string;
  payload: {
    displayName?: string;
    commandName?: string;
    [key: string]: any;
  };
  status: 'pending' | 'processing' | 'completed' | 'failed';
  attempts: number;
  progress?: number;
  exception?: string;
  started_at?: string;
  completed_at?: string;
  created_at: string;
}

export interface ManualMatchRequest {
  type: 'artist' | 'album';
  localId: string;
  musicbrainzId: string;
}

export interface ManualMatchResponse {
  success: boolean;
  message: string;
  preview?: MetadataPreview;
}
