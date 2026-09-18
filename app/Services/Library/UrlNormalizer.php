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
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        $host = $parts['host'];
        // Basic SSRF guard: private literal IPs + cloud metadata.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! self::isPrivateIp($host);
        }
        $lower = strtolower($host);
        if (in_array($lower, ['localhost', 'metadata.google.internal'], true)) {
            return false;
        }
        if (str_ends_with($lower, '.internal') || str_ends_with($lower, '.local')) {
            return false;
        }
        $resolved = gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            return ! self::isPrivateIp($resolved);
        }

        return true;
    }

    public static function isPrivateIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
