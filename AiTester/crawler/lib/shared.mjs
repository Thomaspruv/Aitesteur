// Shared between crawl.mjs (discovery) and execute.mjs (workflow replay):
// the SSRF guard, CLI arg parsing, and the login/register form helpers. Kept
// in one place so a fix to any of these (e.g. a new blocked IP range) applies
// to both scripts automatically instead of drifting between two copies.

import { lookup } from 'node:dns/promises';

export const NAV_TIMEOUT_MS = 15000;

// --- SSRF guard: refuse to navigate to a host that resolves to a private,
// loopback, link-local (includes the 169.254.169.254 cloud metadata
// endpoint), or otherwise non-public IP range. Enforced two ways: a fast
// upfront rejection of the start URL (assertPublicHost, before the browser
// even launches), and installPrivateNetworkGuard() below, which intercepts
// every request on every page/popup in the context for the life of the
// crawl/run — this is what actually stops an HTTP redirect (or a same-
// origin page whose DNS record changes mid-crawl) from reaching an internal
// address, since neither of those is caught by a single upfront check on the
// start URL alone. Known residual risk: a DNS-rebinding attack that changes
// resolution *between* this lookup and the connection Chromium then makes to
// the address it resolved would still slip through — closing that would need
// IP-pinned connections, out of scope here. ---
const BLOCKED_IPV4_RANGES = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10', // carrier-grade NAT
    '127.0.0.0/8',
    '169.254.0.0/16', // link-local, includes cloud metadata endpoints
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '224.0.0.0/4', // multicast
];

function ipv4ToLong(ip) {
    const parts = ip.split('.').map(Number);
    return ((parts[0] << 24) | (parts[1] << 16) | (parts[2] << 8) | parts[3]) >>> 0;
}

function inIpv4Cidr(ip, cidr) {
    const [range, bits] = cidr.split('/');
    const maskBits = Number(bits);
    const mask = maskBits === 0 ? 0 : (~0 << (32 - maskBits)) >>> 0;
    return (ipv4ToLong(ip) & mask) === (ipv4ToLong(range) & mask);
}

function isBlockedIpv4(ip) {
    return BLOCKED_IPV4_RANGES.some((cidr) => inIpv4Cidr(ip, cidr));
}

function isBlockedIpv6(ip) {
    const lower = ip.toLowerCase();
    return (
        lower === '::1' ||
        lower.startsWith('fe80:') || // link-local
        lower.startsWith('fc') ||
        lower.startsWith('fd') || // unique local
        lower.startsWith('::ffff:127.') // IPv4-mapped loopback
    );
}

// A bracketed IPv6 literal (e.g. "[::1]", as URL.hostname keeps it) makes
// dns.lookup() throw ENOTFOUND — it only recognizes a bare address. Stripping
// the brackets first is what lets a legitimate public IPv6 target/redirect
// resolve correctly instead of being misreported as unresolvable/blocked.
function stripIpv6Brackets(hostname) {
    return hostname.startsWith('[') && hostname.endsWith(']') ? hostname.slice(1, -1) : hostname;
}

// 'public' | 'private' | 'unresolvable' — kept as three distinct outcomes
// (rather than a boolean) so assertPublicHost can report which one actually
// happened: a typo'd/not-yet-propagated hostname and an actual SSRF block are
// different problems for the user to act on, and collapsing them into one
// generic "non autorisée" message hid that distinction.
async function classifyHost(hostname) {
    let address, family;
    try {
        ({ address, family } = await lookup(stripIpv6Brackets(hostname)));
    } catch {
        return 'unresolvable';
    }

    const blocked = family === 6 ? isBlockedIpv6(address) : isBlockedIpv4(address);

    return blocked ? 'private' : 'public';
}

async function resolveIsHostPublic(hostname) {
    return (await classifyHost(hostname)) === 'public';
}

export async function assertPublicHost(hostname) {
    const classification = await classifyHost(hostname);

    if (classification === 'unresolvable') {
        throw new Error(`Impossible de résoudre l'hôte : ${hostname}`);
    }

    if (classification === 'private') {
        throw new Error(`Cible non autorisée : ${hostname} pointe vers une adresse réseau privée ou interne.`);
    }
}

// How long a per-hostname public/private verdict is trusted before
// re-resolving. A redirect chain or a busy page can reference the same host
// dozens of times in a row, so caching at all still matters for latency — but
// caching for the crawl/run's *entire* remaining duration (up to ~3 min for
// discovery, ~900s for a workflow run) would hand a DNS-rebinding attacker
// that whole window to flip a hostname's record after its first, legitimate
// resolution. A few seconds keeps the common case (many requests to the same
// host in a tight loop) cheap while keeping the rebinding window short. The
// context.on('response') backstop below still catches an attack that lands
// inside this window — this cap is about narrowing exposure, not the only
// thing standing between a rebind and a real connection.
const HOST_VERDICT_TTL_MS = 5000;

// Installs the guard described above on a BrowserContext — covers the main
// page and any popup opened within it (context.on('page') captures don't get
// their own route() call anywhere, so this is the only thing standing between
// a popup and an internal address). Results are cached per-hostname, each for
// HOST_VERDICT_TTL_MS: a redirect chain or a busy page can reference the same
// host dozens of times, and re-running a DNS lookup for each would add real
// latency for no additional safety.
//
// Three layers, because no single Playwright interception point covers
// everything a page can trigger:
//   - context.route() (below) blocks a request before it's ever sent — but
//     Playwright does NOT invoke it for the target of an HTTP redirect (only
//     the first URL in a redirect chain gets a route handler at all), so a
//     same-origin page that later 302s to an internal address sails through.
//   - context.routeWebSocket() covers ws:/wss: connections specifically —
//     route() never sees WebSocket traffic at all, by design.
//   - context.on('response') is the backstop that actually closes the
//     redirect gap: it fires with the IP address Chromium really connected
//     to (serverAddr()), after the connection already happened. There's no
//     way to abort a single response this late, so a private-address hit
//     kills the whole browser context instead — every further/in-flight
//     Playwright call then rejects, which the caller turns into a failed
//     run rather than a silent success that quietly visited an internal
//     address. The caller must check the returned object's `blockedAddress`
//     after its own try/catch, since a rejection deep in click-exploration
//     can otherwise get swallowed by that code's own resilience catches.
export async function installPrivateNetworkGuard(context) {
    const verdictByHostname = new Map(); // hostname -> { isPublic, checkedAt }
    const guardState = { blockedAddress: null };

    async function isHostPublic(hostname) {
        const cached = verdictByHostname.get(hostname);
        if (cached && Date.now() - cached.checkedAt < HOST_VERDICT_TTL_MS) {
            return cached.isPublic;
        }

        const isPublic = await resolveIsHostPublic(hostname);
        verdictByHostname.set(hostname, { isPublic, checkedAt: Date.now() });
        return isPublic;
    }

    function killIfPrivate(ipAddress) {
        if (guardState.blockedAddress || !ipAddress) return;
        const isPublic = ipAddress.includes(':') ? !isBlockedIpv6(ipAddress) : !isBlockedIpv4(ipAddress);
        if (!isPublic) {
            guardState.blockedAddress = ipAddress;
            context.close().catch(() => {});
        }
    }

    context.on('response', (response) => {
        response
            .serverAddr()
            .then((addr) => killIfPrivate(addr?.ipAddress))
            .catch(() => {});
    });

    // Unlike context.route(), a routed WebSocket does NOT connect to the real
    // server unless connectToServer() is called — so for a blocked host,
    // simply not calling it (the same "do nothing" as an aborted http(s)
    // request) is enough to block it outright.
    await context.routeWebSocket('**/*', async (ws) => {
        let url;
        try {
            url = new URL(ws.url());
        } catch {
            return;
        }

        if (await isHostPublic(url.hostname)) {
            ws.connectToServer();
        }
    });

    await context.route('**/*', async (route) => {
        let url;
        try {
            url = new URL(route.request().url());
        } catch {
            return route.abort('blockedbyclient');
        }

        // Only http(s) requests go out to a remote host at all — data:, blob:,
        // and about:blank (the page's initial state before the first goto)
        // have no hostname to resolve and can't reach anything internal.
        // ws:/wss: is handled separately above — route() never sees it.
        if (!['http:', 'https:'].includes(url.protocol)) {
            return route.continue();
        }

        if (!(await isHostPublic(url.hostname))) {
            return route.abort('blockedbyclient');
        }

        return route.continue();
    });

    return guardState;
}

export function parseArgs(argv) {
    const args = {};
    for (const arg of argv.slice(2)) {
        const match = arg.match(/^--([a-z-]+)=(.*)$/s);
        if (match) {
            args[match[1]] = match[2];
        }
    }
    return args;
}

export function sameOrigin(url, origin) {
    try {
        return new URL(url).origin === origin;
    } catch {
        return false;
    }
}

export const LOGIN_PATTERN = /\blog.?in\b|\bsign.?in\b|connexion|se connecter/i;
export const REGISTER_PATTERN = /regist|sign.?up|inscri|creer.*compte|cr[ée]er.*compte|create.*account|join/i;

// Click-exploration safety net: never click something whose visible label
// suggests it destroys data, changes account state, or ends the crawler's own
// session. This is a second layer on top of the harder rule enforced at the
// call site (only elements outside a <form> are ever clicked at all, so no
// form — destructive or not — can be submitted by exploration); this pattern
// additionally covers non-form actions like a "Delete" icon button or a
// "Log out" menu item that would otherwise be fair game.
export const DANGEROUS_ACTION_PATTERN =
    /delete|supprim|effac|remove|d[ée]sactiv|disable|deactivat|d[ée]sinscri|unsubscribe|r[ée]sili|cancel.*(subscription|account|abonnement)|annuler.*(abonnement|compte)|log.?out|sign.?out|d[ée]connex|se d[ée]connecter|close.*account|delete.*account|purge|wipe|reset.*(password|account)/i;

export async function findSameOriginLink(page, origin, pattern) {
    const links = await page.locator('a[href]').evaluateAll((as) =>
        as.map((a) => ({ href: a.href, text: a.textContent || '' }))
    );
    const match = links.find((link) => sameOrigin(link.href, origin) && pattern.test(link.href + ' ' + link.text));
    return match ? match.href : null;
}

// A single password field looks like a login form; two (password + confirmation)
// looks like a registration form. Simple, but reliable across typical stacks
// (Laravel/Fortify, Devise, Django-allauth, ...) and matches our own app.
export async function classifyForm(page) {
    const passwordCount = await page.locator('input[type="password"]').count();
    const formCount = await page.locator('form').count();

    if (passwordCount >= 2) return 'register';
    if (passwordCount === 1) return 'login';
    if (formCount > 0) return 'generic';
    return null;
}

// Shared by execute.mjs (one per workflow step) and crawl.mjs (one per
// discovered modal/popup) — same format (JPEG data URL) so the PHP side has a
// single decode path (see RunWorkflow/RunDiscoveryCrawl's storeScreenshot()).
export async function captureScreenshot(page) {
    const buf = await page.screenshot({ type: 'jpeg', quality: 60 }).catch(() => null);
    return buf ? `data:image/jpeg;base64,${buf.toString('base64')}` : null;
}

export async function fillAndSubmitAuthForm(page, kind, username, password) {
    const passwordFields = page.locator('input[type="password"]');
    const passwordFieldCount = await passwordFields.count();

    const identifierField = page
        .locator('input[type="email"], input[type="text"]:not([name*="name" i]), input[name*="user" i], input[name*="email" i]')
        .first();
    if ((await identifierField.count()) > 0) {
        await identifierField.fill(username);
    }

    if (kind === 'register') {
        const nameField = page.locator('input[name*="name" i]:not([name*="user" i])').first();
        if ((await nameField.count()) > 0) {
            await nameField.fill('Agentic Testing');
        }
    }

    for (let i = 0; i < passwordFieldCount; i++) {
        await passwordFields.nth(i).fill(password);
    }

    const termsCheckbox = page.locator('input[type="checkbox"][name*="term" i], input[type="checkbox"][name*="agree" i]').first();
    if ((await termsCheckbox.count()) > 0) {
        await termsCheckbox.check().catch(() => {});
    }

    await passwordFields.first().press('Enter');
    await page.waitForLoadState('networkidle', { timeout: NAV_TIMEOUT_MS }).catch(() => {});

    // Best-effort success signal: we're no longer looking at a password form.
    return (await page.locator('input[type="password"]').count()) === 0;
}
