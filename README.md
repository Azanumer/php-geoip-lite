# PHP GeoIP Lite

Dependency-free IP geolocation for PHP. Look up an IP's country, city, ISP and coordinates against a free provider, with a file cache so repeat lookups cost nothing.

## Features

- **Two free providers** — ip-api.com (no key, 45 req/min) by default; ipinfo.io when you pass a token (free tier 50k/month)
- **File cache** — results cached as JSON for 30 days (configurable), written atomically so concurrent readers never see a half-written entry
- **Privacy-safe** — private and reserved IPs are rejected locally and never sent to a provider
- **Helpers** — `countryCode()`, `isEu()` (EU/EEA check for GDPR banners), `lookupBatch()`, `clearCache()`
- **Testable** — inject a custom fetcher (`fn(string $url): ?string`) and never touch the network in tests
- **No dependencies** — cURL with a `file_get_contents` fallback, PHP 7.4+

## Usage

```php
require 'src/GeoIpLite.php';

$geo = new GeoIpLite();

// Full lookup — normalised keys, null when the provider has no value.
$info = $geo->lookup('8.8.8.8');
// [
//   'ip' => '8.8.8.8', 'country_code' => 'US', 'country' => 'United States',
//   'region' => 'California', 'city' => 'Mountain View', 'postal' => '94035',
//   'latitude' => 37.386, 'longitude' => -122.0838,
//   'timezone' => 'America/Los_Angeles', 'isp' => 'Google LLC', 'org' => 'Google LLC',
// ]

echo $geo->countryCode('8.8.8.8'); // US
var_dump($geo->isEu('2.2.2.2'));   // bool — handy for GDPR consent banners

// With an ipinfo.io token (tried first, ip-api.com as fallback):
$geo = new GeoIpLite(['ipinfo_token' => 'YOUR_TOKEN']);

// Custom cache location / TTL:
$geo = new GeoIpLite(['cache_dir' => '/var/cache/geoip', 'cache_ttl' => 86400]);
```

`lookup()` returns `null` for invalid IPs, private/reserved ranges, and when every provider fails — so always null-check the result.

## Requirements

- PHP 7.4+
- cURL recommended (falls back to `file_get_contents`)

## License

MIT — see [LICENSE](LICENSE).
