# Browse Tab Components

This directory contains the components for the music metadata browse feature.

## Components

### `BrowseTab`
The main component that orchestrates the browse functionality.

**Props:**
- `currentMetadata?: Record<string, any>` - Current metadata of the entity (for comparison)
- `onMetadataApplied?: (newMetadata: any) => void` - Callback when metadata is applied
- `entityType?: 'album' | 'artist' | 'song'` - Type of entity to browse (default: 'album')
- `entityId?: number` - ID of the entity to apply metadata to

**Example:**
```tsx
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab';

function MusicMetadataPage() {
  const [album, setAlbum] = useState<AlbumResource>({
    id: 123,
    title: 'Abbey Road',
    artist: 'The Beatles',
    year: 1969
  });

  const handleMetadataApplied = (newMetadata: any) => {
    // Update local state or refetch album
    console.log('Applied metadata:', newMetadata);
  };

  return (
    <BrowseTab
      entityType="album"
      entityId={album.id}
      currentMetadata={album}
      onMetadataApplied={handleMetadataApplied}
    />
  );
}
```

### `SearchForm`
Form component for searching metadata.

**Props:**
- `onSearch: (query: string, type: SearchType, provider: SearchProvider) => void` - Search callback
- `isLoading?: boolean` - Loading state

**Features:**
- Debounced search input (300ms)
- Type selector (Album, Artist, Song)
- Provider filter (All, MusicBrainz, Discogs)
- Auto-search on input change

### `SearchResults`
Virtualized list component for displaying search results.

**Props:**
- `results: SearchResultsData | null` - Search results from API
- `isLoading: boolean` - Loading state
- `error: string | null` - Error message
- `onApplyMetadata: (item, source) => void` - Apply metadata callback
- `isApplying?: boolean` - Apply loading state
- `currentMetadata?: Record<string, any>` - Current metadata for preview
- `onLoadMore?: () => void` - Load more callback
- `hasMore?: boolean` - Whether more results are available

**Features:**
- React Virtuoso virtualization for performance
- Quality badges with color coding
- Preview and Apply actions
- Load more pagination

### `MetadataCard`
Individual metadata result card.

**Props:**
- `data: MetadataItem` - Metadata item (Release or MusicBrainz Release)
- `source: 'musicbrainz' | 'discogs'` - Source provider
- `qualityScore?: number` - Quality score (0-1)
- `onPreview?: () => void` - Preview callback
- `onApply?: () => void` - Apply callback
- `isSelected?: boolean` - Selection state
- `onSelect?: () => void` - Select callback

**Features:**
- Thumbnail/avatar display
- Artist, title, year display
- Quality badge
- Source badge (colored)
- Action buttons

### `QualityBadge`
Quality score badge component.

**Props:**
- `score: number` - Quality score (0-1)

**Color Coding:**
- Green: >= 0.7 (70%)
- Yellow: 0.5 - 0.7 (50-70%)
- Red: < 0.5 (50%)

### `PreviewModal`
Modal for previewing metadata changes before applying.

**Props:**
- `open: boolean` - Modal open state
- `onClose: () => void` - Close callback
- `onApply: () => void` - Apply callback
- `isApplying?: boolean` - Apply loading state
- `newMetadata: { data, source, qualityScore }` - New metadata to preview
- `currentMetadata?: Record<string, any>` - Current metadata for comparison

**Features:**
- Side-by-side comparison
- Highlighted changed fields
- Quality score display
- Source info
- Apply/Cancel actions

## API Endpoints Used

The components expect the following API endpoints to be implemented:

### Search Endpoints
- `GET /api/metadata/browse/albums?query=...&source=...&page=...&per_page=...`
- `GET /api/metadata/browse/artists?query=...&source=...&page=...&per_page=...`
- `GET /api/metadata/browse/songs?query=...&source=...&page=...&per_page=...`

### Apply Endpoint
- `POST /api/metadata/browse/apply`
  ```json
  {
    "entity_type": "album",
    "entity_id": 123,
    "source": "musicbrainz",
    "metadata": { ... }
  }
  ```

## Expected Response Structure

```json
{
  "musicbrainz": {
    "source": "musicbrainz",
    "data": [
      {
        "id": "release-id",
        "title": "Album Title",
        "artist_credit": [
          { "name": "Artist Name" }
        ],
        "date": "1969-09-26",
        "country": "GB",
        "barcode": "...",
        "catalog_number": "...",
        ...
      }
    ],
    "quality_score": 0.85,
    "search_results_count": 10,
    "processed_results_count": 10,
    "best_match": { ... }
  },
  "discogs": {
    "source": "discogs",
    "data": [
      {
        "id": 123,
        "title": "Album Title",
        "artists": [
          { "name": "Artist Name" }
        ],
        "year": 1969,
        "country": "UK",
        "catno": "...",
        "thumbnail": "https://...",
        ...
      }
    ],
    "quality_score": 0.78,
    "search_results_count": 15,
    "processed_results_count": 15,
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 150
    },
    "best_match": { ... }
  }
}
```

## Usage Patterns

### Basic Browse
```tsx
import { BrowseTab } from '@/app/modules/dashboard/music/components/browse-tab';

<BrowseTab />
```

### With Current Metadata
```tsx
<BrowseTab
  entityType="album"
  entityId={album.id}
  currentMetadata={album}
  onMetadataApplied={(newMeta) => {
    // Handle applied metadata
    queryClient.invalidateQueries(['album', album.id]);
  }}
/>
```

### Custom Integration
```tsx
import { SearchForm, SearchResults } from '@/app/modules/dashboard/music/components/browse-tab';

function CustomBrowsePage() {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState(null);

  const handleSearch = async (q, type, provider) => {
    const response = await fetch(`/api/metadata/browse/${type}s?query=${q}&source=${provider}`);
    const data = await response.json();
    setResults(data);
  };

  return (
    <>
      <SearchForm onSearch={handleSearch} />
      <SearchResults
        results={results}
        isLoading={false}
        error={null}
        onApplyMetadata={(item, source) => {
          // Apply logic
        }}
      />
    </>
  );
}
```

## Styling

Components use Radix UI themes and follow the existing design system:

- **Colors**: Gray scale for structure, semantic colors for badges (green, yellow, red, blue, orange)
- **Typography**: Size 1-3 for different hierarchies
- **Spacing**: Radix spacing tokens (1-9)
- **Borders**: Gray-4 for subtle separation

Custom styles are in `search-results.module.scss` for virtualized list styling.

## Performance Considerations

1. **Virtualization**: Search results use React Virtuoso for efficient rendering of large lists
2. **Debouncing**: Search input is debounced to reduce API calls
3. **Lazy Loading**: Load more pagination for efficient data fetching
4. **Memoization**: Components use React.memo for optimal re-rendering

## Accessibility

- Keyboard navigation support
- ARIA labels on interactive elements
- Focus management in modals
- Semantic HTML structure
