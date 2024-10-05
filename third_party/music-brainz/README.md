# MusicBrainz API Client
This project contains an easy to use PHP API client for the [MusicBrainz JSON web service](https://musicbrainz.org/doc/Development/JSON_Web_Service).

## Contents
- [Installation](#installation)
    - [Install with Git](#install-with-git)
    - [Install with Composer](#install-with-composer)
- [Set up](#set-up)
    - [HTTP Adapter](#http-adapter)
    - [Logger](#logger)
    - [MusicBrainz Credentials](#musicbrainz-credentials)
- [Usage](#usage)
    - [Search Requests](#search-requests)
    - [Lookup Requests](#lookup-requests)
    - [Browse Requests](#browse-requests)

## Installation
### Install with Git
```bash
git clone https://github.com/XenosEleatikos/MusicBrainz.git
```

### Install with Composer
This project is available at [Packagist](https://packagist.org): https://packagist.org/packages/xenos/musicbrainz.

You can use it in your project with the following command:
```bash
composer require xenos/musicbrainz
```

## Set up

### HTTP Adapter
As an HTTP client is necessary to the the MusicBrainz API client, you have to provide it as constructor argument. You
may use any HTTP client by using an adapter instantiating ```MusicBrainz\AbstractHttpAdapter```. This projects delivers
an adapter for [Guzzle 6](http://docs.guzzlephp.org/en/stable/), but you may write your own adapter for your preferred
HTTP client.

Usage with Guzzle:

```php
<?php
use MusicBrainz\MusicBrainz;
use MusicBrainz\HttpAdapter\GuzzleHttpAdapter;
use GuzzleHttp\Client;

$guzzleHttpAdapter = new GuzzleHttpAdapter(new Client);
$musicBrainz       = new MusicBrainz($guzzleHttpAdapter);
```

Don't forget to add ``Guzzle`` to your composer project, if not yet required.
```bash
composer require guzzlehttp/guzzle
```

### Logger
Optionally, you may set any PSR-3 logger implementing [```Psr\Log\LoggerInterface```](http://www.php-fig.org/psr/psr-3/)
by injecting it as a second constructor argument.

Usage with Guzzle and Monolog:

```php
<?php
use Monolog\Logger;
use MusicBrainz\MusicBrainz;
use MusicBrainz\HttpAdapter\GuzzleHttpAdapter;
use GuzzleHttp\Client;

$guzzleHttpAdapter = new GuzzleHttpAdapter(new Client);
$logger            = new Logger('MusicBrainz API Client Logger');
$musicBrainz       = new MusicBrainz($guzzleHttpAdapter, $logger);
```

Don't forget to add ``Monolog`` to your composer project, if not yet required.
```bash
composer require monolog/monolog
```

### MusicBrainz Credentials
All requests for ``user-tags`` or ``user-ratings`` require authentication.

```php
<?php
/** @var \MusicBrainz\MusicBrainz $musicBrainz*/
$musicBrainz->config()
    ->setUsername('myUsername')
    ->setPassword('myPassword');
```

### Custom API Path
By default, all requests are executed against the MusicBrainz API at
[https://musicbrainz.org/ws/2](https://musicbrainz.org/ws/2). You can set another base path (e. g. for your own MusicBrainz
installation) as follows:

```php
<?php
/** @var \MusicBrainz\MusicBrainz $musicBrainz*/
$musicBrainz->config()
    ->setApiUrl('https://example.com/ws/2');
```

## Usage

### Search Requests
The search API provides methods to search for the MusicBrainz
[core entities](https://musicbrainz.org/doc/MusicBrainz_Entity). For each of these entities there is a search
method taking a specific filter object and a page filter for pagination. The return value will be a specific
list object containing basic information about the matching entities. For more information you have to perform
a [lookup request](#lookup-requests) with the MusicBrainz Identifier (MBID) of the entity.

The following example search for the artist named "Tom Waits" and limits the result list to the top match.

```php
<?php
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\ArtistFilter;
use MusicBrainz\Value\Name;

$artistFilter = new ArtistFilter;
$artistFilter->addArtistNameWithoutAccents(new Name('Tom Waits'));
$pageFilter = new PageFilter(0, 1);

$artistList = $this->musicBrainz->api()->search()->artist($artistFilter, $pageFilter);
```

### Lookup Requests
If you have the MusicBrainz Identifier (MBID) of an entity (through a [search request](#search-requests)), you
can get more information about it by performing a lookup request. For each of the core entities there is a
specific lookup method. As first argument you have to pass the MBID. The second argument is a list of additional fields
the response object should contain.

The followig example looks up for the artist with MBID c3aeb863-7b26-4388-94e8-5a240f2be21b (who is Tom Waits), and
requests to append information about his his recordings and works to the response.

```php
<?php
use MusicBrainz\Supplement\Lookup\ArtistFields;
use MusicBrainz\Value\MBID;

$fields = (new ArtistFields)
    ->includeRecordings()
    ->includeWorks();

self::$artist = $this->musicBrainz->api()->lookup()->artist(
    new MBID('c3aeb863-7b26-4388-94e8-5a240f2be21b'),
    $fields
);
```

### Browse Requests
Browse requests are a direct lookup of all the entities directly linked to another entity with a given MusicBrainz
Identifier (MBID).

The following example looks up for all events directly linked to the artist with the MBID
c3aeb863-7b26-4388-94e8-5a240f2be21b (who is Tom Waits). In analogy to normal [lookup requests](#lookup-requests)
additional fields may be specified for the response, and a page filter may be added for pagination.

```php
<?php
use MusicBrainz\Filter\Browse\Relation\Entity\EventRelation;
use MusicBrainz\Supplement\Browse\EventFields;

$eventRelation = (new EventRelation)
    ->artist(new MBID('4aae17a7-9f0c-487b-b60e-f8eafb410b1d'));
$eventFields = (new EventFields)
    ->includeTags();
$pageFilter = new PageFilter(0, 5);

$eventList = $musicBrainz->api()->browse()->event($eventRelation, $eventFields, $pageFilter);
```
