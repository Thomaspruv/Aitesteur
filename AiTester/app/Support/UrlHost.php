<?php

namespace App\Support;

class UrlHost
{
    /**
     * Prepends a scheme to a user-typed URL that may be missing one (e.g.
     * "staging.example.com" instead of "https://staging.example.com").
     *
     * The scheme check is case-insensitive — a plain <input type="text"> has
     * no autocapitalize guard, and mobile keyboards routinely capitalize the
     * first letter typed into one, which a case-sensitive check would miss
     * and then double-prepend "https://" onto an already-schemed URL. A
     * literal backslash is also normalized to a forward slash first: WHATWG
     * URL parsers (what a browser/Chromium actually uses) treat '\' as a
     * path/authority separator for http(s) URLs, but PHP's parse_url() does
     * not, so leaving it as-is would let of() and a real browser disagree on
     * where the host ends (e.g. "https://evil.com\@authorized.example.com").
     */
    public static function normalize(string $url): string
    {
        $url = str_replace('\\', '/', $url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
    }

    /**
     * Extract the host from a user-typed URL that may be missing its scheme —
     * used to detect whether a candidate target URL points at a different
     * host than whatever was last authorized, without requiring the user to
     * type a fully-qualified URL. Lowercased, since hostnames are case-
     * insensitive but a plain string comparison isn't.
     */
    public static function of(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url(self::normalize($url), PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }
}
