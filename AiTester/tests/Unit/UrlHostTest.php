<?php

use App\Support\UrlHost;

test('extracts the host from a fully-qualified URL', function () {
    expect(UrlHost::of('https://staging.example.com/path?x=1'))->toBe('staging.example.com');
});

test('prepends a scheme to a schemeless URL before extracting the host', function () {
    expect(UrlHost::of('staging.example.com'))->toBe('staging.example.com');
});

test('is case-insensitive on both the scheme and the host', function () {
    expect(UrlHost::of('HTTPS://Staging.Example.COM'))->toBe('staging.example.com');
});

test('returns null for an empty or null URL', function () {
    expect(UrlHost::of(''))->toBeNull()
        ->and(UrlHost::of(null))->toBeNull();
});

test('normalizes a literal backslash to a forward slash before parsing, matching how a browser would resolve the host', function () {
    // Regression test: WHATWG URL parsers (what a real browser uses) treat a
    // backslash as a path/authority separator for http(s) URLs; PHP's
    // parse_url() alone does not, so "evil.com\@authorized.example.com"
    // used to parse to host "authorized.example.com" here while a real
    // browser would navigate to "evil.com" — a bypass of the authorization
    // comparison this class exists for.
    expect(UrlHost::of('https://evil.com\@authorized.example.com/'))->toBe('evil.com');
});

test('does not double-prepend a scheme onto an uppercase-schemed URL', function () {
    // Regression test: a case-sensitive str_starts_with('https://') check
    // used to miss "HTTPS://..." and prepend another "https://" in front of
    // it, collapsing the extracted host to the garbage string "https".
    expect(UrlHost::of('HTTPS://good.example.com'))->toBe('good.example.com');
});

test('normalize() leaves an already-schemed URL untouched regardless of scheme case', function () {
    expect(UrlHost::normalize('HTTPS://good.example.com'))->toBe('HTTPS://good.example.com')
        ->and(UrlHost::normalize('http://good.example.com'))->toBe('http://good.example.com');
});

test('normalize() prepends https:// to a schemeless URL', function () {
    expect(UrlHost::normalize('good.example.com'))->toBe('https://good.example.com');
});
