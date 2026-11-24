<?php

/**
 * Offline demo: lookup() with an injected fetcher, so no network is needed.
 * Run: php examples/geoip-example.php
 */

require __DIR__ . '/../src/GeoIpLite.php';

// A stub fetcher that answers like ip-api.com, so the demo works offline.
$fetcher = function ($url) {
    return json_encode(array(
        'status'      => 'success',
        'query'       => '8.8.8.8',
        'country'     => 'United States',
        'countryCode' => 'US',
        'regionName'  => 'California',
        'city'        => 'Mountain View',
        'zip'         => '94035',
        'lat'         => 37.386,
        'lon'         => -122.0838,
        'timezone'    => 'America/Los_Angeles',
        'isp'         => 'Google LLC',
        'org'         => 'Google LLC',
    ));
};

$geo = new GeoIpLite(array(
    'cache_dir' => sys_get_temp_dir() . '/geoip-lite-demo',
    'fetcher'   => $fetcher,
));

$info = $geo->lookup('8.8.8.8');
print_r($info);

echo 'Country code: ' . $geo->countryCode('8.8.8.8') . PHP_EOL;
echo 'Is EU? ' . ($geo->isEu('8.8.8.8') ? 'yes' : 'no') . PHP_EOL;

// The second lookup hits the file cache — the fetcher is not called again.
$geo2 = new GeoIpLite(array(
    'cache_dir' => sys_get_temp_dir() . '/geoip-lite-demo',
    'fetcher'   => function ($url) {
        throw new RuntimeException('fetcher should not be called for a cached IP');
    },
));
echo 'Cached lookup: ' . $geo2->countryCode('8.8.8.8') . PHP_EOL;

// Private and invalid IPs are never sent to a provider.
var_dump($geo->lookup('192.168.1.1')); // NULL
var_dump($geo->lookup('not-an-ip'));   // NULL
