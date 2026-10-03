<?php

namespace App\Services\Library;

class UrlNormalizer
{
    public static function normalize(string $url): array
    {
        $url = trim($url);
        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $url, $m)) {
            if (! in_array(strtolower($m[1]), ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Only http(s) URLs allowed');
            }
        } elseif (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid URL');
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http(s) URLs allowed');
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        // Strip tracking params for canonical form.
        parse_str($parts['query'] ?? '', $qs);
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'] as $t) {
            unset($qs[$t]);
        }
        $cleanQuery = $qs ? '?'.http_build_query($qs) : '';
        $canonical = $scheme.'://'.$host.$path.$cleanQuery;

        return ['url' => $url, 'canonical_url' => $canonical, 'host' => $host];
    }

    public static function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true))) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! self::isPrivateIp($host);
        }
        if (! preg_match('/^[a-z0-9.-]+$/D', $host) || preg_match('/^[0-9.]+$/D', $host)
            || in_array($host, ['localhost', 'metadata.google.internal'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.local')) {
            return false;
        }

        return true;
    }

    public static function publicAddresses(string $url): array
    {
        if (! self::isPublicHttpUrl($url)) {
            return [];
        }
        $host = trim(parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record(rtrim($host, '.'), DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip) || self::isPrivateIp($ip)) {
                return [];
            }
            $addresses[] = $ip;
        }

        return array_values(array_unique($addresses));
    }

    public static function isPrivateIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        $bytes = array_values(unpack('C*', $packed));
        if (strlen($packed) === 4) {
            [$first, $second, $third] = $bytes;

            return $first === 0 || $first >= 224
                || ($first === 100 && $second >= 64 && $second <= 127)
                || ($first === 192 && $second === 0 && $third === 0)
                || ($first === 198 && in_array($second, [18, 19], true))
                || ($first === 192 && $second === 0 && $third === 2)
                || ($first === 198 && $second === 51 && $third === 100)
                || ($first === 203 && $second === 0 && $third === 113);
        }

        return ($bytes[0] & 0xE0) !== 0x20
            || ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x0D && $bytes[3] === 0xB8)
            || ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0 && $bytes[3] === 0)
            || ($bytes[0] === 0x20 && $bytes[1] === 0x02);
    }
}
