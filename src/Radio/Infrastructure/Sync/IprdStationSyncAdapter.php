<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Sync;

use App\Radio\Application\Port\StationSyncPortInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IprdStationSyncAdapter implements StationSyncPortInterface
{
    private const SUMMARY_URL = 'https://iprd-org.github.io/iprd/site_data/summary.json';
    private const CATALOG_URL = 'https://iprd-org.github.io/iprd/site_data/metadata/catalog.json';

    /**
     * ISO 3166-1 alpha-2 → English country name, built from IPRD catalog.
     * Used to resolve codes to names without downloading the full catalog.
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAMES = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan',
        'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania',
        'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina',
        'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia',
        'AW' => 'Aruba', 'AX' => 'Åland Islands', 'AZ' => 'Azerbaijan',
        'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh',
        'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria',
        'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin',
        'BM' => 'Bermuda', 'BN' => 'Brunei Darussalam', 'BO' => 'Bolivia, Plurinational State of',
        'BQ' => 'Bonaire, Sint Eustatius and Saba', 'BR' => 'Brazil', 'BS' => 'Bahamas',
        'BT' => 'Bhutan', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize',
        'CA' => 'Canada', 'CD' => 'Congo, The Democratic Republic of the',
        'CF' => 'Central African Republic', 'CG' => 'Congo', 'CH' => 'Switzerland',
        'CI' => "Côte d'Ivoire", 'CK' => 'Cook Islands', 'CL' => 'Chile',
        'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica',
        'CU' => 'Cuba', 'CV' => 'Cabo Verde', 'CW' => 'Curaçao', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador',
        'EE' => 'Estonia', 'EG' => 'Egypt', 'ES' => 'Spain', 'ET' => 'Ethiopia',
        'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands (Malvinas)',
        'FM' => 'Micronesia, Federated States of', 'FO' => 'Faroe Islands',
        'FR' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada',
        'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey', 'GH' => 'Ghana',
        'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea',
        'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea', 'GR' => 'Greece',
        'GT' => 'Guatemala', 'GU' => 'Guam', 'GY' => 'Guyana', 'HK' => 'Hong Kong',
        'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary',
        'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man',
        'IN' => 'India', 'IQ' => 'Iraq', 'IR' => 'Iran, Islamic Republic of',
        'IS' => 'Iceland', 'IT' => 'Italy', 'JE' => 'Jersey', 'JM' => 'Jamaica',
        'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan',
        'KH' => 'Cambodia', 'KM' => 'Comoros', 'KN' => 'Saint Kitts and Nevis',
        'KR' => 'Korea, Republic of', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands',
        'KZ' => 'Kazakhstan', 'LA' => "Lao People's Democratic Republic",
        'LB' => 'Lebanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka', 'LR' => 'Liberia', 'LS' => 'Lesotho',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya',
        'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova, Republic of',
        'ME' => 'Montenegro', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands',
        'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar',
        'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MS' => 'Montserrat',
        'MT' => 'Malta', 'MU' => 'Mauritius', 'MW' => 'Malawi', 'MX' => 'Mexico',
        'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia',
        'NC' => 'New Caledonia', 'NE' => 'Niger', 'NG' => 'Nigeria',
        'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway',
        'NP' => 'Nepal', 'NR' => 'Nauru', 'NZ' => 'New Zealand', 'OM' => 'Oman',
        'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia',
        'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan',
        'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon', 'PR' => 'Puerto Rico',
        'PS' => 'Palestine, State of', 'PT' => 'Portugal', 'PY' => 'Paraguay',
        'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia',
        'RU' => 'Russian Federation', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan',
        'SE' => 'Sweden', 'SG' => 'Singapore',
        'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
        'SI' => 'Slovenia', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone',
        'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia',
        'SR' => 'Suriname', 'SS' => 'South Sudan', 'SV' => 'El Salvador',
        'SX' => 'Sint Maarten (Dutch part)', 'SY' => 'Syrian Arab Republic',
        'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos Islands',
        'TF' => 'French Southern Territories', 'TG' => 'Togo', 'TH' => 'Thailand',
        'TJ' => 'Tajikistan', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan',
        'TN' => 'Tunisia', 'TO' => 'Tonga', 'TR' => 'Türkiye',
        'TT' => 'Trinidad and Tobago', 'TW' => 'Taiwan, Province of China',
        'TZ' => 'Tanzania, United Republic of', 'UA' => 'Ukraine', 'UG' => 'Uganda',
        'UM' => 'United States Minor Outlying Islands', 'US' => 'United States',
        'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
        'VA' => 'Holy See (Vatican City State)',
        'VC' => 'Saint Vincent and the Grenadines',
        'VE' => 'Venezuela, Bolivarian Republic of', 'VG' => 'Virgin Islands, British',
        'VI' => 'Virgin Islands, U.S.', 'VN' => 'Viet Nam', 'VU' => 'Vanuatu',
        'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'XK' => 'Kosovo',
        'YE' => 'Yemen', 'YT' => 'Mayotte', 'ZA' => 'South Africa',
        'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** @var array<string, mixed>|null Cached raw catalog data (only used during sync) */
    private ?array $cachedCatalog = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function fetchCountries(): array
    {
        $response = $this->httpClient->request('GET', self::SUMMARY_URL);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(
                sprintf('Failed to fetch IPRD summary: HTTP %d', $response->getStatusCode()),
            );
        }

        $data = $response->toArray();

        // summary.json: { total_stations, total_countries, countries: [{code, count}, ...] }
        $rawCountries = $data['countries'] ?? [];
        $countries = [];

        foreach ($rawCountries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $code = (string) ($entry['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $countries[] = [
                'code' => $code,
                'name' => self::COUNTRY_NAMES[$code] ?? $code,
                'station_count' => (int) ($entry['count'] ?? 0),
            ];
        }

        return $countries;
    }

    public function fetchStationsByCountry(string $countryCode): array
    {
        $countryName = self::COUNTRY_NAMES[strtoupper($countryCode)] ?? null;

        if ($countryName === null) {
            return [];
        }

        $catalog = $this->fetchCatalog();
        $stations = $catalog['stations'] ?? [];
        $result = [];

        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }

            if (($station['country'] ?? '') !== $countryName) {
                continue;
            }

            $result[] = $this->normalizeStation($station);
        }

        return $result;
    }

    /**
     * Fetch the IPRD catalog (cached after first call within this process).
     *
     * @return array<string, mixed>
     */
    private function fetchCatalog(): array
    {
        if ($this->cachedCatalog !== null) {
            return $this->cachedCatalog;
        }

        $response = $this->httpClient->request('GET', self::CATALOG_URL);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(
                sprintf('Failed to fetch IPRD catalog: HTTP %d', $response->getStatusCode()),
            );
        }

        $this->cachedCatalog = $response->toArray();

        return $this->cachedCatalog;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeStation(array $raw): array
    {
        $streams = [];
        foreach ($raw['streams'] ?? [] as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $streams[] = [
                'url' => $stream['url'] ?? '',
                'format' => $stream['format'] ?? 'unknown',
                'bitrate' => (int) ($stream['bitrate'] ?? 0),
                'reliability' => (float) ($stream['reliability'] ?? 1.0),
            ];
        }

        return [
            'external_id' => (string) ($raw['id'] ?? ''),
            'name' => $raw['name'] ?? 'Unknown',
            'country' => $raw['country'] ?? '',
            'language' => is_array($raw['language'] ?? null)
                ? implode(', ', array_filter($raw['language']))
                : ($raw['language'] ?? null),
            'genres' => $raw['genres'] ?? [],
            'tags' => $raw['tags'] ?? [],
            'streams' => $streams,
            'logo' => $raw['logo'] ?? null,
            'website' => $raw['website'] ?? null,
        ];
    }
}
