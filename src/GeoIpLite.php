<?php

declare(strict_types=1);

/**
 * GeoIpLite — dependency-free IP geolocation.
 *
 * Looks up an IP address against a free provider (ip-api.com by default,
 * ipinfo.io with an optional token) and caches the result in a JSON file
 * so repeat lookups cost nothing. No extensions beyond cURL (with a
 * file_get_contents fallback) are required.
 *
 * Usage:
 *
 *   $geo = new GeoIpLite();
 *   $info = $geo->lookup('8.8.8.8');
 *   // ['country_code' => 'US', 'country' => 'United States', 'city' => 'Mountain View', ...]
 *   $geo->countryCode('8.8.8.8'); // 'US'
 *   $geo->isEu('2.2.2.2');        // true/false
 */
class GeoIpLite
{
    /** @var string Directory used for the file cache. */
    private $cacheDir;

    /** @var int Cache lifetime in seconds (default 30 days). */
    private $cacheTtl;

    /** @var string|null ipinfo.io token; enables the ipinfo provider. */
    private $ipinfoToken;

    /** @var callable|null Custom fetcher: fn(string $url): ?string */
    private $fetcher;

    /** @var string[] EU/EEA country codes (ISO 3166-1 alpha-2). */
    private static $euCodes = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
    );

    /**
     * @param array $options {
     *     @var string      $cache_dir    Cache directory (default: sys_get_temp_dir() . '/geoip-lite').
     *     @var int         $cache_ttl    Cache lifetime in seconds (default: 2592000 = 30 days).
     *     @var string|null $ipinfo_token ipinfo.io token; when set, ipinfo is tried first.
     *     @var callable|null $fetcher    Custom HTTP fetcher fn(string $url): ?string (for tests).
     * }
     */
    public function __construct(array $options = array())
    {
        $this->cacheDir    = isset($options['cache_dir']) ? rtrim($options['cache_dir'], '/') : sys_get_temp_dir() . '/geoip-lite';
        $this->cacheTtl    = isset($options['cache_ttl']) ? (int) $options['cache_ttl'] : 2592000;
        $this->ipinfoToken = isset($options['ipinfo_token']) ? $options['ipinfo_token'] : null;
        $this->fetcher     = isset($options['fetcher']) ? $options['fetcher'] : null;
    }

    /**
     * Look up an IP address.
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return array|null Normalised result, or null when the IP is invalid,
     *                    private/reserved, or every provider failed.
     *                    Keys: ip, country_code, country, region, city,
     *                    postal, latitude, longitude, timezone, isp, org.
     */
    public function lookup($ip)
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        // Never send private/reserved addresses to a provider.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $cached = $this->readCache($ip);
        if ($cached !== null) {
            return $cached;
        }

        $result = null;
        if ($this->ipinfoToken !== null) {
            $result = $this->lookupIpinfo($ip);
        }
        if ($result === null) {
            $result = $this->lookupIpApi($ip);
        }
        if ($result === null) {
            return null;
        }

        $this->writeCache($ip, $result);
        return $result;
    }

    /**
     * Two-letter country code for an IP, or null when unknown.
     */
    public function countryCode($ip)
    {
        $info = $this->lookup($ip);
        return $info !== null ? $info['country_code'] : null;
    }

    /**
     * Whether an IP is located in the EU/EEA.
     */
    public function isEu($ip)
    {
        $code = $this->countryCode($ip);
        return $code !== null && in_array($code, self::$euCodes, true);
    }

    /**
     * Look up several IPs, reusing the cache for each.
     *
     * @param string[] $ips
     * @return array Map of ip => lookup result (null entries omitted).
     */
    public function lookupBatch(array $ips)
    {
        $out = array();
        foreach ($ips as $ip) {
            $info = $this->lookup($ip);
            if ($info !== null) {
                $out[$ip] = $info;
            }
        }
        return $out;
    }

    /**
     * Delete the whole cache directory contents.
     *
     * @return int Number of cache files removed.
     */
    public function clearCache()
    {
        $removed = 0;
        foreach (glob($this->cacheDir . '/*.json') as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * ip-api.com (free, no key, 45 requests/minute from one IP).
     */
    private function lookupIpApi($ip)
    {
        $url  = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,query';
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? null) !== 'success') {
            return null;
        }
        return $this->normalise(array(
            'ip'           => $data['query'] ?? $ip,
            'country_code' => $data['countryCode'] ?? null,
            'country'      => $data['country'] ?? null,
            'region'       => $data['regionName'] ?? null,
            'city'         => $data['city'] ?? null,
            'postal'       => $data['zip'] ?? null,
            'latitude'     => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude'    => isset($data['lon']) ? (float) $data['lon'] : null,
            'timezone'     => $data['timezone'] ?? null,
            'isp'          => $data['isp'] ?? null,
            'org'          => $data['org'] ?? null,
        ));
    }

    /**
     * ipinfo.io (free tier 50k/month, token required).
     */
    private function lookupIpinfo($ip)
    {
        $url  = 'https://ipinfo.io/' . rawurlencode($ip) . '/json?token=' . rawurlencode($this->ipinfoToken);
        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }
        $lat = $lon = null;
        if (!empty($data['loc']) && strpos($data['loc'], ',') !== false) {
            list($lat, $lon) = explode(',', $data['loc'], 2);
        }
        return $this->normalise(array(
            'ip'           => $data['ip'] ?? $ip,
            'country_code' => $data['country'] ?? null,
            'country'      => $data['country'] ?? null,
            'region'       => $data['region'] ?? null,
            'city'         => $data['city'] ?? null,
            'postal'       => $data['postal'] ?? null,
            'latitude'     => $lat !== null ? (float) $lat : null,
            'longitude'    => $lon !== null ? (float) $lon : null,
            'timezone'     => $data['timezone'] ?? null,
            'isp'          => null,
            'org'          => $data['org'] ?? null,
        ));
    }

    /**
     * Guarantee every key exists (null when the provider did not send it)
     * and normalise the country code to upper case.
     */
    private function normalise(array $row)
    {
        $keys = array('ip', 'country_code', 'country', 'region', 'city', 'postal', 'latitude', 'longitude', 'timezone', 'isp', 'org');
        $out  = array();
        foreach ($keys as $key) {
            $out[$key] = array_key_exists($key, $row) ? $row[$key] : null;
        }
        if (is_string($out['country_code'])) {
            $out['country_code'] = strtoupper($out['country_code']);
        }
        return $out;
    }

    /**
     * Fetch a URL: custom fetcher first, then cURL, then file_get_contents.
     *
     * @return string|null Response body, or null on any failure.
     */
    private function fetch($url)
    {
        if ($this->fetcher !== null) {
            return call_user_func($this->fetcher, $url);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_USERAGENT, 'GeoIpLite/1.0');
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                return null;
            }
            return $body;
        }
        $ctx = stream_context_create(array(
            'http' => array('timeout' => 5, 'header' => "User-Agent: GeoIpLite/1.0\r\n"),
        ));
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    private function cacheFile($ip)
    {
        return $this->cacheDir . '/' . hash('sha256', $ip) . '.json';
    }

    private function readCache($ip)
    {
        $file = $this->cacheFile($ip);
        if (!is_file($file)) {
            return null;
        }
        if (time() - filemtime($file) > $this->cacheTtl) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function writeCache($ip, array $result)
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        if (!is_dir($this->cacheDir)) {
            return;
        }
        // Atomic write: temp file + rename, so a concurrent reader never
        // sees a half-written cache entry.
        $tmp = $this->cacheDir . '/.' . hash('sha256', $ip) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($result), LOCK_EX) !== false) {
            @rename($tmp, $this->cacheFile($ip));
        }
    }
}
