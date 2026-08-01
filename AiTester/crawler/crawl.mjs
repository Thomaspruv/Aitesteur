#!/usr/bin/env node
// Autonomous discovery crawl (dossier §4).
//
// Breadth-first, same-origin only, capped by --max-pages and MAX_DEPTH.
// Discovers new pages several ways: following <a href> links (including ones
// revealed by scrolling to the bottom a few times, for infinite-scroll/lazy-
// loaded content); clicking non-form UI (buttons, menus, "load more" controls,
// modal triggers — see exploreClicks()) to surface SPA routes, in-place
// content, and popups/dialogs that a link-only crawl would never see. New
// browser tabs/windows the target opens (window.open, target="_blank", an
// OAuth/payment popup) are also captured via the context's 'page' event
// instead of being invisible to the crawl. Dialogs that appear on their own —
// a timed entry ad, an exit-intent popup, a cookie banner — are caught too
// (see detectAndCloseDialog(), checked once per page in addition to after
// every click). Cross-origin links (partner sites, docs, social) are recorded
// as popup nodes rather than silently dropped, even though they're never
// followed (out of scope for the same-origin/SSRF guard).
//
// Safety boundary: exploration never submits a form, destructive or not —
// every click candidate is required to sit outside a <form> element, and
// labels matching DANGEROUS_ACTION_PATTERN (delete, logout, cancel
// subscription, ...) are skipped regardless. The only forms this script ever
// fills and submits are a best-effort login/registration attempt: when
// credentials are supplied, login is tried first; if that doesn't work (or no
// login form is found), the crawler looks for a same-origin register/signup
// link and tries to create an account with those credentials, then crawls
// authenticated from wherever that leaves it.
//
// This script only reports raw, deterministic signals — it does not decide
// candidate workflow names itself. loginFormDetected/registerFormDetected are
// booleans, and formPages carries enough context (title, visible heading, form
// field labels) for the caller to name each one, either with a deterministic
// fallback or by asking an LLM what the page is actually for. Popup/modal
// nodes carry their own heading (the dialog's title, or the popup window's
// <title>) for the same purpose.
//
// Usage:
//   node crawl.mjs --url=https://example.com --output=/path/out.json [--max-pages=20]
//   (optional login credentials are read from CRAWL_USERNAME / CRAWL_PASSWORD
//   env vars rather than CLI args, to avoid leaking them via `ps`)
//
// Always writes valid JSON to --output and exits 0, so the caller's parsing
// is uniform regardless of whether the crawl itself succeeded.

import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import {
    NAV_TIMEOUT_MS,
    assertPublicHost,
    parseArgs,
    sameOrigin,
    LOGIN_PATTERN,
    REGISTER_PATTERN,
    DANGEROUS_ACTION_PATTERN,
    findSameOriginLink,
    classifyForm,
    fillAndSubmitAuthForm,
    captureScreenshot,
    installPrivateNetworkGuard,
} from './lib/shared.mjs';

const MAX_PAGES_DEFAULT = 20;
const MAX_DEPTH = 3;
const MAX_FORM_CANDIDATES = 5;

// Click-exploration bounds: this walks UI the crawler doesn't otherwise
// understand (menus, tooltips, "what's this" popups), so every number here is
// a deliberate ceiling on how much unknown surface a single crawl will probe.
const MAX_CLICKS_PER_PAGE = 6;
const MAX_POPUPS_TOTAL = 15;
const MAX_MODALS_TOTAL = 15;
const POPUP_LOAD_TIMEOUT_MS = 5000;
const MAX_SCROLL_PASSES = 3;
const SCROLL_SETTLE_MS = 500;
const AUTO_DIALOG_WAIT_MS = 1200;

// Only elements outside a <form> are ever click-candidates — this is the hard
// safety boundary (see DANGEROUS_ACTION_PATTERN's comment): no click made
// during exploration can ever submit a form, destructive or not, because it
// never targets anything a <form> contains. That covers nav toggles, menus,
// "learn more" popups, cookie banners, and modal triggers, which is most of
// what "note every popup" actually means in practice.
const CLICK_SELECTOR = 'button, [role="button"], summary, [aria-haspopup="true"], [aria-haspopup="dialog"], [aria-haspopup="menu"], [data-toggle="modal"], [data-bs-toggle="modal"]';
// `.modal` catches the many modal implementations (jQuery plugins, older
// Bootstrap clones, hand-rolled ones) that never bother with role="dialog"/
// aria-modal — safe to include broadly because every match is still required
// to pass an isVisible() check below, so a template's hidden `.modal` div
// never counts as "open".
const DIALOG_SELECTOR = '[role="dialog"], [aria-modal="true"], dialog[open], [role="menu"], .modal';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Collapse a path segment to {id} only when it's confidently an identifier —
// numeric, a UUID, or a hex-charset string that contains at least one digit
// (a hex-only run with zero digits, like "decade" or "cafebabe", is far more
// likely to be a real word/slug than a hash, so it's left alone). Erring
// towards NOT collapsing is deliberate: a false collapse silently merges two
// distinct pages and drops one from the crawl, which is worse than an
// occasional un-collapsed real ID inflating the page count slightly.
function templateKey(pathname) {
    const segments = pathname.split('/').filter(Boolean).map((segment) => {
        if (/^\d+$/.test(segment)) return '{id}';
        if (UUID_PATTERN.test(segment)) return '{id}';
        if (segment.length >= 8 && /^[0-9a-f]+$/i.test(segment) && /\d/.test(segment)) return '{id}';

        return segment;
    });
    return '/' + segments.join('/');
}

const MAX_FIELD_SIGNALS = 8;

// document.title is frequently the same static string on every route of a
// client-rendered app (set once in index.html and never updated per page),
// which is why relying on it for a page label makes every node in the graph
// end up with the same name. The first on-page heading is route-specific far
// more often and is a much better signal to label a page with.
async function extractHeading(page) {
    const heading = await page.locator('h1, h2').first().innerText().catch(() => '');

    return heading ? heading.trim().slice(0, 80) : null;
}

// Enough context for a caller (deterministic fallback or an LLM) to guess what
// a form page is actually for, without shipping the whole page content.
async function extractFormSignals(page, heading) {
    const fields = await page
        .locator('form input:not([type="hidden"]):not([type="password"]), form textarea, form select')
        .evaluateAll((els) =>
            els
                .slice(0, 8)
                .map((el) => el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.getAttribute('name') || '')
                .filter(Boolean)
        );

    return {
        heading,
        fields: fields.slice(0, MAX_FIELD_SIGNALS),
    };
}

// Detects a dialog/menu already visible on the page — whether it just
// appeared because of a click, or because the page itself opened it on load
// (a timed entry ad, an exit-intent popup, ...). Screenshots it before
// dismissing (Escape, then a close button, then as a last resort reloading
// `fallbackUrl`) so exploration can keep going against a clean DOM either way.
// Returns null when nothing is open.
async function detectAndCloseDialog(page, fallbackUrl) {
    const dialog = page.locator(DIALOG_SELECTOR).first();
    if ((await dialog.count().catch(() => 0)) === 0 || !(await dialog.isVisible().catch(() => false))) {
        return null;
    }

    const heading = await dialog.locator('h1, h2, h3').first().innerText().catch(() => '');
    const label = (heading || (await dialog.innerText().catch(() => ''))).trim().slice(0, 80);
    // Captured before dismissing it below — this is the only chance to see
    // what the dialog actually looked like.
    const screenshot = await captureScreenshot(page);

    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(150);

    let domReset = false;
    if (await dialog.isVisible().catch(() => false)) {
        const closeButton = page
            .locator('[aria-label="Close" i], [aria-label="Fermer" i], [data-dismiss], .modal-close')
            .first();
        if ((await closeButton.count().catch(() => 0)) > 0) {
            await closeButton.click({ timeout: 1000 }).catch(() => {});
        } else if (fallbackUrl) {
            await page.goto(fallbackUrl, { waitUntil: 'domcontentloaded' }).catch(() => {});
            domReset = true;
        }
    }

    return { label: label || 'Fenêtre modale', screenshot, domReset };
}

// Explores UI the crawler doesn't otherwise understand: menus, tooltips,
// modal triggers. Only ever clicks elements outside a <form> (see
// CLICK_SELECTOR's comment above) and skips anything matching
// DANGEROUS_ACTION_PATTERN, so exploration can never submit a form or fire an
// account-destroying/logout action. Bounded to MAX_CLICKS_PER_PAGE per page —
// one misbehaving control is swallowed so the rest still get a turn.
async function exploreClicks(page, origin) {
    const originalUrl = page.url();
    const locator = page.locator(CLICK_SELECTOR);

    const raw = await locator
        .evaluateAll((els) =>
            els.map((el) => ({
                text: (el.textContent || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 80),
                visible: !!el.offsetParent,
                inForm: !!el.closest('form'),
            }))
        )
        .catch(() => []);

    const seen = new Set();
    const indexes = [];
    raw.forEach((candidate, index) => {
        if (!candidate.visible || candidate.inForm || candidate.text === '') return;
        if (DANGEROUS_ACTION_PATTERN.test(candidate.text)) return;

        const dedupeKey = candidate.text.toLowerCase();
        if (seen.has(dedupeKey)) return;
        seen.add(dedupeKey);
        indexes.push(index);
    });

    const discoveries = [];
    const hrefLocator = page.locator('a[href]');

    // Lazily computed on first use, and invalidated (set back to null) any
    // time the DOM is reset by a goto() below — a stale baseline would make
    // every href on the reloaded page look "new".
    let knownHrefs = null;
    const snapshotHrefs = () => hrefLocator.evaluateAll((links) => links.map((a) => a.href)).catch(() => []);

    for (const index of indexes.slice(0, MAX_CLICKS_PER_PAGE)) {
        try {
            const target = locator.nth(index);
            if (!(await target.isVisible().catch(() => false))) continue;

            if (knownHrefs === null) {
                knownHrefs = new Set(await snapshotHrefs());
            }

            await target.click({ timeout: 3000 });
            // Let a modal, menu, or SPA route change settle before inspecting.
            await page.waitForTimeout(400);

            const newUrl = page.url();
            if (newUrl !== originalUrl) {
                if (sameOrigin(newUrl, origin)) {
                    discoveries.push({ type: 'nav', url: newUrl });
                }
                // Reset to the page we started this exploration pass on so the
                // next candidate is evaluated against the same baseline DOM.
                await page.goto(originalUrl, { waitUntil: 'domcontentloaded' }).catch(() => {});
                knownHrefs = null;
                continue;
            }

            const modal = await detectAndCloseDialog(page, originalUrl);
            if (modal) {
                discoveries.push({ type: 'modal', label: modal.label, screenshot: modal.screenshot });
                if (modal.domReset) knownHrefs = null;
            } else {
                // No navigation, no dialog — likely a "load more"/infinite-scroll
                // control, or content revealed in place (e.g. an accordion).
                // Diff against the pre-click baseline so same-origin links
                // injected by the click, which page-load-time harvesting could
                // never have seen, still get queued.
                for (const href of await snapshotHrefs()) {
                    if (knownHrefs.has(href)) continue;
                    knownHrefs.add(href);
                    if (sameOrigin(href, origin)) {
                        discoveries.push({ type: 'nav', url: href });
                    }
                }
            }
        } catch {
            // A single misbehaving control shouldn't stop exploration of the rest of the page.
        }
    }

    return discoveries;
}

async function main() {
    const args = parseArgs(process.argv);
    const outputPath = args.output;
    const maxPages = Number(args['max-pages']) || MAX_PAGES_DEFAULT;

    const result = {
        ok: false,
        nodes: [],
        edges: [],
        formPages: [],
        loginFormDetected: false,
        registerFormDetected: false,
        pagesVisited: 0,
        authenticated: false,
        error: null,
    };

    const finish = () => {
        if (outputPath) {
            writeFileSync(outputPath, JSON.stringify(result));
        }
    };

    if (!args.url) {
        result.error = 'Aucune URL fournie.';
        finish();
        return;
    }

    let startUrl;
    try {
        startUrl = new URL(args.url);
        if (!['http:', 'https:'].includes(startUrl.protocol)) {
            throw new Error('protocol');
        }
    } catch {
        result.error = "L'URL fournie n'est pas valide.";
        finish();
        return;
    }

    try {
        await assertPublicHost(startUrl.hostname);
    } catch (error) {
        result.error = error.message;
        finish();
        return;
    }

    const origin = startUrl.origin;
    const username = process.env.CRAWL_USERNAME || '';
    const password = process.env.CRAWL_PASSWORD || '';
    let browser;

    let guard;
    try {
        browser = await chromium.launch({ headless: true });
        // serviceWorkers: 'block' — Playwright's own request interception
        // (context.route(), used by installPrivateNetworkGuard below) does not
        // see requests made by a Service Worker, so a page registering one
        // could otherwise reach an internal address with zero SSRF screening.
        const context = await browser.newContext({ ignoreHTTPSErrors: true, serviceWorkers: 'block' });
        // assertPublicHost above only screened the start URL — a redirect (or
        // a same-origin host whose DNS record changes mid-crawl) is never
        // re-checked otherwise. This intercepts every request on this context
        // (main page and any popup) for its whole lifetime, closing that gap.
        guard = await installPrivateNetworkGuard(context);
        const page = await context.newPage();
        page.setDefaultTimeout(NAV_TIMEOUT_MS);
        page.setDefaultNavigationTimeout(NAV_TIMEOUT_MS);

        // --- Popup / new-tab capture ---------------------------------------
        // Anything the target opens as a separate page (window.open, a
        // target="_blank" link, an OAuth/payment popup) is invisible to a
        // crawler that only follows in-page navigation. `currentKey`/
        // `currentDepth` are updated at the top of each main-loop iteration
        // (and again around each click in exploreClicks) so a popup opened at
        // any point during that page's processing is attributed to it — safe
        // because nothing here awaits across a queue-loop iteration boundary
        // without also updating them first.
        const discoveredPopups = [];
        const popupEdges = [];
        const popupSeenKeys = new Set();
        let currentKey = null;
        let currentDepth = 0;

        // Declared here (rather than down by the crawl loop that owns them)
        // so a popup fired during the initial page load or the auth flow
        // below — both of which run before that loop starts — can still
        // safely read/queue into them instead of hitting a temporal-dead-zone
        // ReferenceError.
        const visited = new Map(); // templateKey -> node
        const queuedKeys = new Set();
        const queue = [];

        context.on('page', (popup) => {
            handlePopup(popup).catch(() => {});
        });

        async function handlePopup(popup) {
            try {
                await popup.waitForLoadState('domcontentloaded', { timeout: POPUP_LOAD_TIMEOUT_MS });
            } catch {
                // Take whatever loaded in the timeout window — better than nothing.
            }

            try {
                const popupUrl = popup.url();
                const fromKey = currentKey;

                if (sameOrigin(popupUrl, origin)) {
                    const key = templateKey(new URL(popupUrl).pathname);
                    if (!visited.has(key) && !queuedKeys.has(key) && visited.size + queuedKeys.size < maxPages) {
                        queuedKeys.add(key);
                        queue.push({ url: popupUrl, depth: Math.min(currentDepth + 1, MAX_DEPTH), fromKey });
                    }
                } else if (discoveredPopups.length < MAX_POPUPS_TOTAL) {
                    // Cross-origin (OAuth, payment widget, external help center):
                    // out of scope to crawl further (the SSRF/same-origin guard
                    // only covers `origin`), but still worth recording — this is
                    // exactly the "note everything" the crawl otherwise misses.
                    const key = `popup:${popupUrl}`;
                    if (!popupSeenKeys.has(key)) {
                        popupSeenKeys.add(key);
                        const title = await popup.title().catch(() => popupUrl);
                        // Captured before close() below — a cross-origin popup
                        // is never visited again, so this is the only record of
                        // what it actually showed.
                        const screenshot = await captureScreenshot(popup);
                        discoveredPopups.push({ key, heading: title || popupUrl, url: popupUrl, kind: 'popup', screenshot });
                        if (fromKey) popupEdges.push({ from: fromKey, to: key });
                    }
                }
            } catch {
                // Best-effort only — a popup we can't inspect is still closed below.
            }

            await popup.close().catch(() => {});
        }

        // Deliberately outside the auth try/catch below — a failure loading
        // the landing page itself (target down, DNS failure, timeout) is a
        // real crawl failure and must propagate to the outer catch, not be
        // swallowed by the "auth is best-effort" handler meant only for the
        // login/register flow that follows.
        await page.goto(startUrl.toString(), { waitUntil: 'domcontentloaded' });

        // --- Best-effort auth: try to log in, and if that doesn't work, try to
        // register a fresh account with the given credentials. Only ever fills
        // and submits login/register forms — no other button is ever clicked. ---
        let loginFormDetected = false;
        let registerFormDetected = false;
        let authenticated = false;

        try {
            let kind = await classifyForm(page);
            if (kind === 'login') loginFormDetected = true;
            if (kind === 'register') registerFormDetected = true;

            if (username && password) {
                // Step 1 — try to log in. The entry page is rarely the login page
                // itself (it's usually a marketing/home page), so look for a
                // same-origin login link first if we're not already on one.
                let loginUrl = kind === 'login' ? page.url() : await findSameOriginLink(page, origin, LOGIN_PATTERN);

                if (loginUrl) {
                    if (loginUrl !== page.url()) {
                        await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
                        kind = await classifyForm(page);
                    }
                    if (kind === 'login') {
                        loginFormDetected = true;
                        authenticated = await fillAndSubmitAuthForm(page, 'login', username, password);
                    }
                }

                // Step 2 — login wasn't available or didn't work: try registering
                // a fresh account with the same credentials instead.
                if (!authenticated) {
                    let registerUrl = kind === 'register' ? page.url() : null;

                    if (!registerUrl) {
                        if (page.url() !== startUrl.toString()) {
                            await page.goto(startUrl.toString(), { waitUntil: 'domcontentloaded' }).catch(() => {});
                        }
                        registerUrl = await findSameOriginLink(page, origin, REGISTER_PATTERN);
                    }

                    if (registerUrl) {
                        if (registerUrl !== page.url()) {
                            await page.goto(registerUrl, { waitUntil: 'domcontentloaded' });
                        }
                        const registerKind = await classifyForm(page);
                        if (registerKind === 'register') {
                            registerFormDetected = true;
                            authenticated = await fillAndSubmitAuthForm(page, 'register', username, password);
                        }
                    }
                }
            }
        } catch {
            // Auth is best-effort; continue crawling as anonymous on failure.
        }

        // --- Breadth-first, same-origin crawl (link-following + click-driven) ---
        const edgeSet = new Set();
        const edges = [];
        const formPages = [];
        const modalNodes = [];
        const modalEdges = [];

        const landingUrl = page.url();
        queue.push({ url: landingUrl, depth: 0, fromKey: null });
        queuedKeys.add(templateKey(new URL(landingUrl).pathname));

        while (queue.length > 0 && visited.size < maxPages) {
            const { url, depth, fromKey } = queue.shift();
            const key = templateKey(new URL(url).pathname);

            if (visited.has(key)) {
                if (fromKey && fromKey !== key && !edgeSet.has(`${fromKey}>${key}`)) {
                    edgeSet.add(`${fromKey}>${key}`);
                    edges.push({ from: fromKey, to: key });
                }
                continue;
            }

            currentKey = key;
            currentDepth = depth;

            try {
                if (url !== landingUrl) {
                    await page.goto(url, { waitUntil: 'domcontentloaded' });
                }

                const title = (await page.title()) || key;
                const heading = await extractHeading(page);
                visited.set(key, { key, heading, url });

                if (fromKey && fromKey !== key && !edgeSet.has(`${fromKey}>${key}`)) {
                    edgeSet.add(`${fromKey}>${key}`);
                    edges.push({ from: fromKey, to: key });
                }

                // Some dialogs (an entry ad, an exit-intent popup, a cookie
                // consent overlay) open on a timer rather than in response to
                // any click — wait briefly for one to appear, then dismiss it
                // the same way exploreClicks() would, before the page is
                // otherwise inspected (an overlay's own form could otherwise
                // fool classifyForm() below into misreading the real page).
                await page.waitForSelector(DIALOG_SELECTOR, { timeout: AUTO_DIALOG_WAIT_MS }).catch(() => {});
                const autoModal = await detectAndCloseDialog(page, url);
                if (autoModal && modalNodes.length < MAX_MODALS_TOTAL) {
                    const modalKey = `modal:${key}:auto`;
                    modalNodes.push({
                        key: modalKey,
                        heading: autoModal.label,
                        url: null,
                        kind: 'modal',
                        screenshot: autoModal.screenshot,
                    });
                    modalEdges.push({ from: key, to: modalKey });
                }

                const pageKind = await classifyForm(page);
                if (pageKind === 'login') {
                    loginFormDetected = true;
                } else if (pageKind === 'register') {
                    registerFormDetected = true;
                } else if (pageKind === 'generic' && formPages.length < MAX_FORM_CANDIDATES) {
                    const signals = await extractFormSignals(page, heading);
                    formPages.push({ title, url, ...signals });
                }

                if (depth < MAX_DEPTH) {
                    const extractLinks = (els) =>
                        els.map((a) => ({ href: a.href, text: (a.textContent || '').trim().slice(0, 60) }));
                    const links = await page.locator('a[href]').evaluateAll(extractLinks);

                    // Infinite-scroll / lazy-loaded content often only renders
                    // once the user scrolls near the bottom — nudge the page
                    // down a few times and fold in whatever that reveals, same
                    // as a link that was already there on load. Stops early
                    // once the page stops growing (nothing left to reveal).
                    const seenHrefs = new Set(links.map((link) => link.href));
                    for (let i = 0; i < MAX_SCROLL_PASSES; i++) {
                        const previousHeight = await page.evaluate(() => document.body.scrollHeight).catch(() => 0);
                        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {});
                        await page.waitForTimeout(SCROLL_SETTLE_MS);
                        const newHeight = await page.evaluate(() => document.body.scrollHeight).catch(() => 0);

                        const scrolledLinks = await page.locator('a[href]').evaluateAll(extractLinks).catch(() => []);
                        for (const link of scrolledLinks) {
                            if (seenHrefs.has(link.href)) continue;
                            seenHrefs.add(link.href);
                            links.push(link);
                        }

                        if (newHeight <= previousHeight) break;
                    }

                    for (const link of links) {
                        if (!sameOrigin(link.href, origin)) {
                            // A cross-origin link (partner site, docs, social
                            // media) is real content the target chose to
                            // surface — dropping it silently would contradict
                            // "note everything". Never followed (the SSRF/
                            // same-origin guard only covers `origin`), just
                            // recorded, same as an OAuth/payment popup.
                            if (discoveredPopups.length >= MAX_POPUPS_TOTAL) continue;

                            const extKey = `popup:${link.href}`;
                            if (popupSeenKeys.has(extKey)) continue;
                            popupSeenKeys.add(extKey);
                            discoveredPopups.push({
                                key: extKey,
                                heading: link.text || link.href,
                                url: link.href,
                                kind: 'popup',
                                screenshot: null,
                            });
                            popupEdges.push({ from: key, to: extKey });
                            continue;
                        }

                        const linkKey = templateKey(new URL(link.href).pathname);
                        if (visited.has(linkKey) || queuedKeys.has(linkKey)) continue;
                        if (visited.size + queuedKeys.size >= maxPages) continue;

                        queuedKeys.add(linkKey);
                        queue.push({ url: link.href, depth: depth + 1, fromKey: key });
                    }

                    const clickDiscoveries = await exploreClicks(page, origin).catch(() => []);
                    for (const discovery of clickDiscoveries) {
                        if (discovery.type === 'nav') {
                            const linkKey = templateKey(new URL(discovery.url).pathname);
                            if (visited.has(linkKey) || queuedKeys.has(linkKey)) continue;
                            if (visited.size + queuedKeys.size >= maxPages) continue;

                            queuedKeys.add(linkKey);
                            queue.push({ url: discovery.url, depth: depth + 1, fromKey: key });
                        } else if (discovery.type === 'modal' && modalNodes.length < MAX_MODALS_TOTAL) {
                            const modalKey = `modal:${key}:${modalNodes.length}`;
                            modalNodes.push({
                                key: modalKey,
                                heading: discovery.label,
                                url: null,
                                kind: 'modal',
                                screenshot: discovery.screenshot ?? null,
                            });
                            modalEdges.push({ from: key, to: modalKey });
                        }
                    }
                }
            } catch {
                // Skip pages that fail to load (timeout, redirect loop, etc.) and keep crawling.
            }
        }

        await browser.close();
        browser = null;

        const nodeList = Array.from(visited.values());
        nodeList.forEach((node, i) => {
            node.kind = i === 0 ? 'current' : 'neutral';
        });

        // Modal/popup nodes are appended after the real pages so the "first
        // node = current page" rule above is unaffected by them, then every
        // node gets its grid position in one pass.
        const allNodes = [...nodeList, ...modalNodes, ...discoveredPopups];
        allNodes.forEach((node, i) => {
            node.x = 20 + (i % 6) * 40;
            node.y = 20 + Math.floor(i / 6) * 35;
        });

        result.ok = true;
        result.nodes = allNodes;
        result.edges = [...edges, ...modalEdges, ...popupEdges];
        result.formPages = formPages;
        result.loginFormDetected = loginFormDetected;
        result.registerFormDetected = registerFormDetected;
        result.pagesVisited = nodeList.length;
        result.authenticated = authenticated;
    } catch (error) {
        result.error = error?.message || 'Erreur inconnue pendant le crawl.';
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
    }

    // installPrivateNetworkGuard closes the context the moment it observes a
    // response from a private/internal address (the redirect backstop — see
    // its comment in shared.mjs) — that context closure surfaces as ordinary
    // "Target closed" errors deep inside the crawl loop, many of which are
    // individually swallowed by that code's own per-page/per-click resilience
    // catches. Overriding the result here, after everything else has settled,
    // is what turns "quietly produced a truncated but ok:true result" into an
    // explicit, correctly-diagnosed failure.
    if (guard?.blockedAddress) {
        result.ok = false;
        result.error = `Cible non autorisée : une réponse provenait de l'adresse réseau privée/interne ${guard.blockedAddress}.`;
    }

    finish();
}

main();
