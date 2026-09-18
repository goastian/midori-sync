<?php

namespace App\Services\Library;

/**
 * Extracts OpenGraph metadata + sanitized readable HTML for the reader.
 * No external dependencies: plain regex + PHP DOMDocument.
 */
class MetadataExtractor
{
    private const ALLOWED_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'br', 'ul', 'ol', 'li', 'a',
        'strong', 'em', 'b', 'i', 'blockquote', 'pre', 'code', 'img', 'figure', 'figcaption', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];

    private const ALLOWED_ATTRS = ['href' => ['a'], 'src' => ['img'], 'alt' => ['img'], 'title' => ['a', 'img']];

    public static function extract(string $html, string $pageUrl): array
    {
        $out = [];
        $out['title'] = self::metaTag($html, 'title');
        $out['description'] = self::metaTag($html, 'description') ?? self::metaTag($html, 'og_desc');
        $out['og_image'] = self::absolutize(self::metaTag($html, 'og_image'), $pageUrl);
        $out['favicon'] = self::absolutize(self::favicon($html) ?? '/favicon.ico', $pageUrl);
        if (empty($out['title'])) {
            $out['title'] = self::metaTag($html, 'og_title');
        }

        $readable = self::readableHtml($html, $pageUrl);
        if ($readable['words'] > 30) {
            $out['readable'] = $readable['html'];
            $out['reading_time'] = (int) max(1, ceil($readable['words'] / 200));
        }

        return $out;
    }

    private static function metaTag(string $html, string $key): ?string
    {
        $patterns = [
            'title' => '/<title[^>]*>(.*?)<\/title>/is',
            'description' => '/<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\']/is',
            'og_image' => '/<meta[^>]+property=["\']og:image["\'][^>]*content=["\'](.*?)["\']/is',
            'og_title' => '/<meta[^>]+property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/is',
            'og_desc' => '/<meta[^>]+property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/is',
        ];
        if (! isset($patterns[$key])) {
            return null;
        }
        if (preg_match($patterns[$key], $html, $m)) {
            $v = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            // content="..." with single quotes inside breaks the regex; trim at the next orphan double quote.
            if ($key !== 'title') {
                $v = trim(explode("\n", $v)[0]);
            }

            return $v !== '' ? mb_substr($v, 0, 2000) : null;
        }

        return null;
    }

    private static function favicon(string $html): ?string
    {
        if (preg_match('/<link[^>]+rel=["\'](?:shortcut |alternate )?icon["\'][^>]+href=["\'](.*?)["\']/is', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    public static function absolutize(?string $url, string $pageUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'data:')) {
            return null;
        }
        $base = parse_url($pageUrl);
        if ($base === false || empty($base['host'])) {
            return null;
        }
        $origin = ($base['scheme'] ?? 'https').'://'.$base['host'].(! empty($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        $dir = isset($base['path']) ? rtrim(dirname($base['path']), '/') : '';

        return $origin.$dir.'/'.$url;
    }

    /**
     * @return array{html: ?string, words: int}
     */
    public static function readableHtml(string $html, string $pageUrl): array
    {
        // Recorta al <article>/<main> si existe, si no al <body>.
        $scope = $html;
        if (preg_match('/<article[^>]*>(.*?)<\/article>/is', $html, $m)) {
            $scope = $m[1];
        } elseif (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $m)) {
            $scope = $m[1];
        }
        // Elimina bloques ruidosos/peligrosos.
        $scope = preg_replace('#<(script|style|nav|footer|header|aside|form|iframe|canvas|video|audio|noscript|select|button)[^>]*>.*?</\1>#is', '', $scope);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$scope.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $allowed = array_flip(self::ALLOWED_TAGS);
        $xpath = new \DOMXPath($doc);
        // Walk in reverse so nodes can be safely replaced/removed.
        $nodes = iterator_to_array($xpath->query('//*'));
        foreach (array_reverse($nodes) as $node) {
            /** @var \DOMElement $node */
            if ($node->nodeName === 'div' || $node->nodeName === 'span' || $node->nodeName === 'section') {
                // Unwrap containers: keep children, drop the tag.
                $frag = $doc->createDocumentFragment();
                while ($node->firstChild) {
                    $frag->appendChild($node->firstChild);
                }
                $node->parentNode?->replaceChild($frag, $node);

                continue;
            }
            if (! isset($allowed[strtolower($node->nodeName)])) {
                $node->parentNode?->removeChild($node);

                continue;
            }
            // Attributes: href/src/alt/title only, and no javascript:.
            foreach (iterator_to_array($node->attributes ?? []) as $attr) {
                $an = strtolower($attr->nodeName);
                $tag = strtolower($node->nodeName);
                $ok = isset(self::ALLOWED_ATTRS[$an]) && in_array($tag, self::ALLOWED_ATTRS[$an], true);
                $val = $attr->nodeValue ?? '';
                if (! $ok || preg_match('#^\s*javascript:#i', $val) || str_starts_with($an, 'on')) {
                    $node->removeAttribute($attr->nodeName);

                    continue;
                }
                if (in_array($an, ['href', 'src'], true)) {
                    $abs = self::absolutize($val, $pageUrl);
                    if ($abs === null) {
                        $node->removeAttribute($attr->nodeName);
                    } else {
                        $node->setAttribute($attr->nodeName, $abs);
                    }
                }
            }
            if ($node->nodeName === 'a' && ! $node->hasAttribute('href')) {
                // Convierte anchors sin href en span de texto.
                $span = $doc->createElement('span', $node->textContent);
                $node->parentNode?->replaceChild($span, $node);
            }
        }

        $clean = trim($doc->saveHTML() ?? '');
        $words = str_word_count(strip_tags($clean));
        if ($words <= 30) {
            return ['html' => null, 'words' => $words];
        }

        return ['html' => mb_substr($clean, 0, 60000), 'words' => $words];
    }
}
