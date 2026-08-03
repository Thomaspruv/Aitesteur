// Fast, offline unit tests for the pure logic in shared.mjs — the private-IP
// range matching and hostname classification that the whole SSRF guard rests
// on. Deliberately network-independent: every case here uses a literal IP
// address (dns.lookup() short-circuits on those, no real resolution happens)
// or a reserved-invalid hostname (RFC 2606's .invalid TLD, guaranteed to
// never resolve), so this suite runs the same way offline as in CI.

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    isBlockedIpv4,
    isBlockedIpv6,
    assertPublicHost,
    parseArgs,
    sameOrigin,
    LOGIN_PATTERN,
    REGISTER_PATTERN,
    DANGEROUS_ACTION_PATTERN,
} from './shared.mjs';

describe('isBlockedIpv4', () => {
    const blocked = [
        ['0.0.0.0', 'this-network'],
        ['10.0.0.1', 'RFC1918 10/8'],
        ['10.255.255.255', 'RFC1918 10/8 upper bound'],
        ['100.64.0.1', 'carrier-grade NAT'],
        ['127.0.0.1', 'loopback'],
        ['127.255.255.255', 'loopback upper bound'],
        ['169.254.169.254', 'cloud metadata endpoint'],
        ['169.254.0.1', 'link-local'],
        ['172.16.0.1', 'RFC1918 172.16/12'],
        ['172.31.255.255', 'RFC1918 172.16/12 upper bound'],
        ['192.0.0.1', 'IETF protocol assignments'],
        ['192.168.0.1', 'RFC1918 192.168/16'],
        ['192.168.255.255', 'RFC1918 192.168/16 upper bound'],
        ['198.18.0.1', 'benchmarking'],
        ['224.0.0.1', 'multicast'],
    ];

    for (const [ip, label] of blocked) {
        test(`blocks ${ip} (${label})`, () => {
            assert.strictEqual(isBlockedIpv4(ip), true);
        });
    }

    const publicIps = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.0', '192.169.0.1'];

    for (const ip of publicIps) {
        test(`does not block public address ${ip}`, () => {
            assert.strictEqual(isBlockedIpv4(ip), false);
        });
    }
});

describe('isBlockedIpv6', () => {
    const blocked = ['::1', 'fe80::1', 'FE80::1', 'fc00::1', 'fd00::1', '::ffff:127.0.0.1'];

    for (const ip of blocked) {
        test(`blocks ${ip}`, () => {
            assert.strictEqual(isBlockedIpv6(ip), true);
        });
    }

    const publicIps = ['2606:4700:4700::1111', '2001:4860:4860::8888'];

    for (const ip of publicIps) {
        test(`does not block public address ${ip}`, () => {
            assert.strictEqual(isBlockedIpv6(ip), false);
        });
    }
});

describe('assertPublicHost', () => {
    test('throws a distinct message for a private IPv4 address', async () => {
        await assert.rejects(() => assertPublicHost('127.0.0.1'), /adresse réseau privée ou interne/);
    });

    test('throws a distinct message for an unresolvable hostname', async () => {
        // "invalid" is reserved by RFC 2606 to never resolve — deterministic, no live network dependency.
        await assert.rejects(() => assertPublicHost('this-must-not-resolve.invalid'), /Impossible de résoudre/);
    });

    test('does not throw for a public IPv4 address', async () => {
        await assert.doesNotReject(() => assertPublicHost('8.8.8.8'));
    });

    test('correctly classifies a bracketed IPv6 loopback literal as private, not unresolvable', async () => {
        // Regression test: dns.lookup() throws ENOTFOUND on a bracketed literal
        // like "[::1]" (URL.hostname keeps the brackets) unless they're
        // stripped first — this used to surface as the wrong error message.
        await assert.rejects(() => assertPublicHost('[::1]'), /adresse réseau privée ou interne/);
    });
});

describe('parseArgs', () => {
    test('parses --key=value pairs after the first two argv entries', () => {
        const args = parseArgs(['node', 'crawl.mjs', '--url=https://example.com', '--max-pages=20']);
        assert.deepStrictEqual(args, { url: 'https://example.com', 'max-pages': '20' });
    });

    test('ignores malformed arguments', () => {
        const args = parseArgs(['node', 'crawl.mjs', 'not-a-flag', '--novalue']);
        assert.deepStrictEqual(args, {});
    });

    test('keeps "=" characters inside the value intact', () => {
        const args = parseArgs(['node', 'crawl.mjs', '--url=https://example.com/?a=1&b=2']);
        assert.strictEqual(args.url, 'https://example.com/?a=1&b=2');
    });
});

describe('sameOrigin', () => {
    test('true for identical origins regardless of path', () => {
        assert.strictEqual(sameOrigin('https://example.com/a/b?x=1', 'https://example.com'), true);
    });

    test('false for a different host', () => {
        assert.strictEqual(sameOrigin('https://evil.com/a', 'https://example.com'), false);
    });

    test('false for a different scheme', () => {
        assert.strictEqual(sameOrigin('http://example.com/a', 'https://example.com'), false);
    });

    test('false (not throwing) for a malformed URL', () => {
        assert.strictEqual(sameOrigin('not a url', 'https://example.com'), false);
    });
});

describe('LOGIN_PATTERN / REGISTER_PATTERN', () => {
    test('LOGIN_PATTERN matches common login labels', () => {
        for (const text of ['Log in', 'Sign in', 'Connexion', 'Se connecter']) {
            assert.strictEqual(LOGIN_PATTERN.test(text), true, text);
        }
    });

    test('LOGIN_PATTERN does not match register labels', () => {
        assert.strictEqual(LOGIN_PATTERN.test('Créer un compte'), false);
    });

    test('REGISTER_PATTERN matches common register labels', () => {
        for (const text of ['Sign up', 'Register', 'Inscription', 'Créer un compte', 'Create account']) {
            assert.strictEqual(REGISTER_PATTERN.test(text), true, text);
        }
    });
});

describe('DANGEROUS_ACTION_PATTERN', () => {
    test('matches destructive/session-ending labels', () => {
        for (const text of ['Delete account', 'Supprimer', 'Log out', 'Se déconnecter', 'Cancel subscription', "Résilier l'abonnement"]) {
            assert.strictEqual(DANGEROUS_ACTION_PATTERN.test(text), true, text);
        }
    });

    test('does not match ordinary navigation labels', () => {
        for (const text of ['Voir le tableau de bord', 'Paramètres', 'Suivant', 'Enregistrer']) {
            assert.strictEqual(DANGEROUS_ACTION_PATTERN.test(text), false, text);
        }
    });
});
