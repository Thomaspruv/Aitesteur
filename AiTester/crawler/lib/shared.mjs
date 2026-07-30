// Shared between crawl.mjs (discovery) and execute.mjs (workflow replay):
// the SSRF guard, CLI arg parsing, and the login/register form helpers. Kept
// in one place so a fix to any of these (e.g. a new blocked IP range) applies
// to both scripts automatically instead of drifting between two copies.

import { lookup } from 'node:dns/promises';

export const NAV_TIMEOUT_MS = 15000;

// --- SSRF guard: refuse to navigate to a host that resolves to a private,
// loopback, link-local (includes the 169.254.169.254 cloud metadata
// endpoint), or otherwise non-public IP range. Checked once against the
// target's start URL before the browser ever launches — every subsequent
// navigation is same-origin (see sameOrigin() below), so this single check
// covers the whole session. Known residual risk, accepted for this simplified
// V1: DNS rebinding between this check and the actual page loads is not
// defended against (would need IP-pinned connections, out of scope here). ---
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

export async function assertPublicHost(hostname) {
    let address, family;
    try {
        ({ address, family } = await lookup(hostname));
    } catch {
        throw new Error(`Impossible de résoudre l'hôte : ${hostname}`);
    }

    const blocked = family === 6 ? isBlockedIpv6(address) : isBlockedIpv4(address);
    if (blocked) {
        throw new Error(`Cible non autorisée : ${hostname} pointe vers une adresse réseau privée ou interne.`);
    }
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
