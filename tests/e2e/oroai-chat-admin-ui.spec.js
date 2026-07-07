// @ts-check

/**
 * Browser-driven e2e test for the OroAI Chat widget in the admin backoffice
 * header ("AI Assistant"), complementing `oroai-chat.spec.js` (which only
 * exercises the raw HTTP API).
 *
 * This spec drives the real page: it logs in, loads the admin dashboard,
 * types into the header chat input, clicks send, and asserts on what
 * actually renders — locking in two bugs fixed in this bundle:
 *   1. The widget must sit inline with the header search bar, not wrap onto
 *      its own row (a stray `display: flex` instead of `inline-flex`).
 *   2. A non-JSON response (login redirect, ACL denial, etc.) must render as
 *      a graceful in-widget error, never surface as an unhandled
 *      "Unexpected token '<'" browser JSON-parse crash.
 *
 * Uses a dedicated `oroai_test_admin` account (OROAI_TEST_ADMIN_USERNAME /
 * OROAI_TEST_ADMIN_PASSWORD), provisioned on demand via the
 * `genaker:oroai:test:ensure-admin` console command — the same account
 * ChatMessageEndpointTest.php uses — so this suite never depends on the real
 * "admin" account's password.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

/**
 * Minimal KEY=VALUE .env parser with ${VAR} expansion (mirrors what
 * Symfony's Dotenv does with .env-app's cross-references, e.g.
 * ORO_SEARCH_ENGINE_DSN=${ORO_SEARCH_URL}?prefix=oro_search).
 *
 * Getting this right matters beyond just this file: Playwright's worker
 * process requires every spec file in the directory for test collection,
 * so oroai-chat.spec.js's own loadEnvFile() call runs in the same process
 * as this one. Without expansion, whichever file loads first sets
 * ORO_SEARCH_ENGINE_DSN to the literal unexpanded string "${ORO_SEARCH_URL}
 * ?prefix=oro_search" in process.env — and since Symfony's own Dotenv
 * never overwrites a variable that's already externally set, any
 * execSync()'d PHP child process (e.g. this file's `beforeAll`) inherits
 * that broken value and fails with 'The "${ORO_SEARCH_URL}?prefix=
 * oro_search" search engine DSN is invalid.'
 */
function loadEnvFile(filePath) {
    if (!fs.existsSync(filePath)) {
        return;
    }

    for (const line of fs.readFileSync(filePath, 'utf8').split('\n')) {
        const trimmed = line.trim();

        if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
            continue;
        }

        const separator = trimmed.indexOf('=');
        const key = trimmed.slice(0, separator).trim();
        let value = trimmed.slice(separator + 1).trim();

        // Strip a single matching pair of surrounding quotes (e.g.
        // ORO_LOG_STACKTRACE_LEVEL="error" in .env-app). Left as-is, the
        // literal quote characters end up in process.env and get inherited
        // by any execSync()'d PHP child process, which chokes on them
        // (Monolog rejects '"error"' as an invalid level string).
        if (
            value.length >= 2
            && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
        ) {
            value = value.slice(1, -1);
        }

        // Expand ${VAR} references against already-set process.env values
        // (both files load .env-app.local before .env-app, so cross-refs
        // resolve in the same order Symfony's Dotenv would resolve them).
        value = value.replace(/\$\{([A-Z0-9_]+)\}/g, (match, refKey) => (
            refKey in process.env ? process.env[refKey] : match
        ));

        if (!(key in process.env)) {
            process.env[key] = value;
        }
    }
}

// Repo root is six levels up from src/Genaker/Bundle/OroAI/tests/e2e.
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..', '..', '..', '..');
loadEnvFile(path.join(REPO_ROOT, '.env-app.local'));
loadEnvFile(path.join(REPO_ROOT, '.env-app'));

const SCHEME = process.env.ORO_TEST_HTTP_SCHEME || 'http';
const HOST = process.env.ORO_TEST_HTTP_HOST || 'localhost';
const PORT = process.env.ORO_TEST_HTTP_PORT || '8000';
const BASE_URL = `${SCHEME}://${HOST}:${PORT}`;

// Same env vars phpunit-dev.xml sets for ChatMessageEndpointTest.php, and the
// same defaults genaker:oroai:test:ensure-admin falls back to.
const ADMIN_USERNAME = process.env.OROAI_TEST_ADMIN_USERNAME || 'oroai_test_admin';
const ADMIN_PASSWORD = process.env.OROAI_TEST_ADMIN_PASSWORD || 'OroAiTest123!';

const ADMIN_LOGIN_URL = `${BASE_URL}/admin/user/login`;
const ADMIN_LOGIN_CHECK_URL = `${BASE_URL}/admin/user/login-check`;
const ADMIN_DASHBOARD_URL = `${BASE_URL}/admin/`;

test.beforeAll(() => {
    // Idempotent: creates the test admin user if missing, or backfills a
    // missing business unit on an existing one. Safe to run every time.
    execSync('php bin/console genaker:oroai:test:ensure-admin', {
        cwd: REPO_ROOT,
        stdio: 'pipe',
    });
});

/**
 * Authenticate the browser context as the OroAI test admin.
 *
 * Oro's login form ships client-side validation (form-validate-view) that
 * can swallow a raw automated button click, so — like the repo's Python
 * `admin_login` fixture (tests/e2e/conftest.py) — we drive the real
 * `/admin/user/login-check` endpoint directly via `context.request` rather
 * than filling and clicking the form. `context.request` shares its cookie
 * jar with `page`, so the resulting session is what `page.goto()` sees.
 */
async function loginAsAdmin(context) {
    const loginPage = await context.request.get(ADMIN_LOGIN_URL);
    const html = await loginPage.text();

    const inputMatch = html.match(/<input[^>]*name="_csrf_token"[^>]*>/);
    const valueMatch = inputMatch && inputMatch[0].match(/value="([^"]*)"/);
    if (!valueMatch) {
        throw new Error('Could not find _csrf_token on the admin login page.');
    }

    await context.request.post(ADMIN_LOGIN_CHECK_URL, {
        form: {
            _username: ADMIN_USERNAME,
            _password: ADMIN_PASSWORD,
            _csrf_token: valueMatch[1],
            _target_path: '',
            _failure_path: '',
        },
    });
}

test.describe('OroAI Chat widget — admin UI', () => {
    // The config's `fullyParallel: true` can otherwise schedule this file's
    // tests onto separate workers, each running its own `beforeAll` — two
    // concurrent `php bin/console` invocations race on writing the same
    // var/cache/dev directory, corrupting parameter resolution (surfaced as
    // dotenv placeholders like "${ORO_SEARCH_URL}" being left unexpanded).
    test.describe.configure({ mode: 'serial' });


    test.beforeEach(async ({ context, browserName }) => {
        test.skip(browserName !== 'chromium', 'single-browser UI smoke test, no need to run per-browser');
        await loginAsAdmin(context);
    });

    test('widget sits inline with the header search bar, not stacked below it', async ({ page }) => {
        await page.goto(ADMIN_DASHBOARD_URL);

        const chatInput = page.locator('#oroai-hc-input');
        const searchToggle = page.locator('.header-dropdown-search').first();

        await expect(chatInput).toBeVisible();
        await expect(searchToggle).toBeVisible();

        const chatBox = await chatInput.boundingBox();
        const searchBox = await searchToggle.boundingBox();
        expect(chatBox).not.toBeNull();
        expect(searchBox).not.toBeNull();

        // Inline means roughly the same vertical center as its header
        // sibling. The `display: flex` regression this guards against made
        // the widget wrap onto its own row, tens of pixels lower.
        const chatCenterY = chatBox.y + chatBox.height / 2;
        const searchCenterY = searchBox.y + searchBox.height / 2;
        expect(Math.abs(chatCenterY - searchCenterY)).toBeLessThan(20);
    });

    test('sending a message renders a reply or a graceful error, never a raw JS crash', async ({ page }) => {
        const pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        await page.goto(ADMIN_DASHBOARD_URL);

        await page.locator('#oroai-hc-input').click();
        await page.locator('#oroai-hc-input').fill('hi');
        await page.locator('#oroai-hc-send').click();

        // The user's own "hi" bubble renders immediately.
        await expect(page.locator('#oroai-hc-msgs .oroai-hc-msg.user').last()).toHaveText('hi');

        // Wait for the assistant reply or an in-widget error bubble — either
        // is acceptable (the LLM provider may be unreachable in this
        // environment); a silent hang or an uncaught exception is not.
        const reply = page.locator('#oroai-hc-msgs .oroai-hc-msg.assistant, #oroai-hc-msgs .oroai-hc-msg.error');
        await expect(reply.first()).toBeVisible({ timeout: 30000 });
        await expect(page.locator('.oroai-hc-loading')).toHaveCount(0, { timeout: 30000 });

        const errorTexts = await page.locator('#oroai-hc-msgs .oroai-hc-msg.error').allTextContents();
        for (const text of errorTexts) {
            expect(text).not.toMatch(/Unexpected token/i);
        }

        expect(pageErrors).toEqual([]);
    });

    /**
     * Regression guard: a real multi-turn conversation through the widget's
     * own JS, which accumulates `history` after every exchange
     * (oroai-chat.js's `history.push(...)`) and sends it on the next
     * message. The Role enum used to be defined inside ChatMessage.php with
     * no file of its own, so it only autoloaded as a side effect of
     * ChatMessage loading first -- something that happened on a
     * conversation's first message (empty history) but not on any
     * follow-up, where ChatController::parseHistory() hit Role::tryFrom()
     * first and fataled with "Attempted to load class Role". A single-
     * message test can't catch that; this one sends two.
     */
    test('a second message in the same conversation still gets a reply, not a fatal error', async ({ page }) => {
        const pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        await page.goto(ADMIN_DASHBOARD_URL);

        const input = page.locator('#oroai-hc-input');
        const sendBtn = page.locator('#oroai-hc-send');
        const reply = page.locator('#oroai-hc-msgs .oroai-hc-msg.assistant, #oroai-hc-msgs .oroai-hc-msg.error');

        await input.click();
        await input.fill('Where are customer users?');
        await sendBtn.click();
        await expect(reply.first()).toBeVisible({ timeout: 30000 });
        await expect(page.locator('.oroai-hc-loading')).toHaveCount(0, { timeout: 30000 });

        // Second message -- the widget's JS now sends non-empty history.
        await input.click();
        await input.fill('And what about orders?');
        await sendBtn.click();

        await expect(page.locator('#oroai-hc-msgs .oroai-hc-msg.user').last()).toHaveText('And what about orders?');
        await expect(reply.nth(1)).toBeVisible({ timeout: 30000 });
        await expect(page.locator('.oroai-hc-loading')).toHaveCount(0, { timeout: 30000 });

        const bubbleTexts = await page.locator('#oroai-hc-msgs .oroai-hc-msg').allTextContents();
        for (const text of bubbleTexts) {
            expect(text).not.toMatch(/Unexpected token/i);
            expect(text).not.toMatch(/Attempted to load class/i);
        }

        expect(pageErrors).toEqual([]);
    });
});
