# Discogs API Client

The Discogs API Client provides a simple interface to interact with the Discogs API. It supports searching for artists, releases, masters, and labels, as well as looking up specific entities by their IDs. The client also supports pagination for all endpoints that return multiple results.

## Installation

The Discogs API Client is already integrated into the Baander application. No additional installation is required.

## Configuration

To use the Discogs API Client, you need to set your Discogs API key in the `.env` file:

```
DISCOGS_API_KEY=your_api_key_here
```

## Basic Usage

### Initialization

```php
use App\Http\Integrations\Discogs\DiscogsClient;

// Using dependency injection (recommended)
public function __construct(DiscogsClient $discogsClient)
{
    $this->discogsClient = $discogsClient;
}

// Or manually
$discogsClient = app(DiscogsClient::class);
```

### Searching

The client supports searching for artists, releases, masters, and labels. Each search method accepts a filter object that specifies the search criteria and pagination parameters.

#### Artists

```php
use App\Http\Integrations\Discogs\Filters\ArtistFilter;

$filter = new ArtistFilter(
    q: 'Radiohead',
    page: 1,
    per_page: 10
);

$artists = $discogsClient->search->artist($filter);
```

#### Releases

```php
use App\Http\Integrations\Discogs\Filters\ReleaseFilter;

$filter = new ReleaseFilter(
    artist: 'Radiohead',
    title: 'OK Computer',
    page: 1,
    per_page: 10
);

$releases = $discogsClient->search->release($filter);
```

#### Masters

```php
use App\Http\Integrations\Discogs\Filters\MasterFilter;

$filter = new MasterFilter(
    artist: 'Radiohead',
    title: 'OK Computer',
    page: 1,
    per_page: 10
);

$masters = $discogsClient->search->master($filter);
```

#### Labels

```php
use App\Http\Integrations\Discogs\Filters\LabelFilter;

$filter = new LabelFilter(
    q: 'XL Recordings',
    page: 1,
    per_page: 10
);

$labels = $discogsClient->search->label($filter);
```

### Pagination

All search methods support pagination. You can specify the page number and the number of items per page in the filter object. You can also retrieve pagination information from the last search:

```php
$pagination = $discogsClient->search->getPagination();
// Returns: ['page' => 1, 'pages' => 10, 'items' => 100, 'per_page' => 10]
```

### Looking Up Entities

The client supports looking up specific entities by their IDs.

#### Artists

```php
$artist = $discogsClient->lookup->artist(3840); // Radiohead
```

#### Artist Releases

```php
$releases = $discogsClient->lookup->artistReleases(3840, 1, 10); // Radiohead's releases, page 1, 10 per page
```

#### Releases

```php
$release = $discogsClient->lookup->release(1475088); // OK Computer
```

#### Masters

```php
$master = $discogsClient->lookup->master(45524); // OK Computer (master)
```

#### Master Versions

```php
$versions = $discogsClient->lookup->masterVersions(45524, 1, 10); // OK Computer versions, page 1, 10 per page
```

#### Labels

```php
$label = $discogsClient->lookup->label(1814); // XL Recordings
```

#### Label Releases

```php
$releases = $discogsClient->lookup->labelReleases(1814, 1, 10); // XL Recordings releases, page 1, 10 per page
```

## Models

The Discogs API Client uses model classes to represent the data returned from the API. These models provide a structured way to access the data and ensure type safety.

### Available Models

- `Artist`: Represents an artist entity
- `Release`: Represents a release entity
- `Master`: Represents a master release entity
- `Label`: Represents a label entity

### Using Models

All lookup and search methods return model instances or collections of model instances. For example:

```php
// Looking up an artist returns an Artist model
$artist = $discogsClient->lookup->artist(3840);
echo $artist->name; // "Radiohead"
echo $artist->profile; // Artist's profile text

// Searching for releases returns an array of Release models
$releases = $discogsClient->search->release($filter);
foreach ($releases as $release) {
    echo $release->title;
    echo $release->year;
}
```

### Model Properties

Each model has properties that correspond to the fields in the API response. Some common properties include:

- `id`: The unique identifier for the entity
- `name` or `title`: The name or title of the entity
- `uri`: The URI for the entity on Discogs
- `resource_url`: The API URL for the entity
- `thumbnail` and `cover_image`: URLs for the entity's images

Refer to the model classes for a complete list of properties for each entity type.

## Examples

See the `examples/discogs_client_example.php` file for more examples of how to use the Discogs API Client.

## Testing

The client includes a comprehensive test suite. You can run the tests with:

```bash
php artisan test --filter=DiscogsClientTest
```
