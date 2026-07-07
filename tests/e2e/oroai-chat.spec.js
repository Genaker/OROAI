// @ts-check

/**
 * E2E test for the OroAI Chat API (`POST /admin/oroai/chat/message`).
 *
 * Exercises the full HTTP stack — routing, ACL/authentication, controller
 * validation, and JSON response structure — end to end.
 *
 * See the ComiVoyager spec (`comivoyager-routing.spec.js`) for the reference
 * pattern this file mirrors.
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

/**
 * Parse a KEY=VALUE file and set any variables not already present in
 * `process.env`. Mirrors `tests/e2e/conftest.py`'s `_load_env_file()` at the
 * repo root.
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
        // ORO_LOG_STACKTRACE_LEVEL="error" in .env-app).
        if (
            value.length >= 2
            && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
        ) {
            value = value.slice(1, -1);
        }

        // Expand ${VAR} references against already-set process.env values
        // (e.g. ORO_SEARCH_ENGINE_DSN=${ORO_SEARCH_URL}?prefix=oro_search in
        // .env-app). Without this, the literal unexpanded string ends up in
        // process.env — harmless here, but Playwright's worker process
        // requires every spec file in the directory for test collection, so
        // this pollutes the shared process env for any other spec (e.g.
        // oroai-chat-admin-ui.spec.js) that execSync()'s a PHP command:
        // Symfony's Dotenv never overwrites a variable that's already
        // externally set, so the child process inherits the broken value.
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

// Dedicated OroAI test admin (same account ChatMessageEndpointTest.php and
// oroai-chat-admin-ui.spec.js use, provisioned via genaker:oroai:test:ensure-admin)
// rather than the real "admin" account, whose actual password isn't known here.
const ADMIN_USERNAME = process.env.OROAI_TEST_ADMIN_USERNAME || 'oroai_test_admin';
const ADMIN_PASSWORD = process.env.OROAI_TEST_ADMIN_PASSWORD || 'OroAiTest123!';

const MESSAGE_ENDPOINT = `${BASE_URL}/admin/oroai/chat/message`;
const ADMIN_LOGIN_URL = `${BASE_URL}/admin/user/login`;
const ADMIN_LOGIN_CHECK_URL = `${BASE_URL}/admin/user/login-check`;

/**
 * Authenticates `request`'s cookie jar as the admin back-office user.
 * Loads the login page to get a session cookie + CSRF token, then POSTs
 * credentials directly to `/admin/user/login-check`.
 */
async function loginAsAdmin(request) {
    const loginPage = await request.get(ADMIN_LOGIN_URL);
    const html = await loginPage.text();

    const inputMatch = html.match(/<input[^>]*name="_csrf_token"[^>]*>/);
    const valueMatch = inputMatch && inputMatch[0].match(/value="([^"]*)"/);

    if (!valueMatch) {
        throw new Error('Could not find _csrf_token on the admin login page.');
    }

    await request.post(ADMIN_LOGIN_CHECK_URL, {
        form: {
            _username: ADMIN_USERNAME,
            _password: ADMIN_PASSWORD,
            _csrf_token: valueMatch[1],
            _target_path: '',
            _failure_path: '',
        },
    });
}

test.describe('POST /admin/oroai/chat/message — OroAI Chat API', () => {
    // fullyParallel can schedule this file's tests onto separate workers,
    // each running its own beforeAll — two concurrent `php bin/console`
    // invocations race on writing the same var/cache/dev directory.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        // Idempotent: creates the test admin user if missing, or backfills a
        // missing business unit on an existing one. Safe to run every time.
        execSync('php bin/console genaker:oroai:test:ensure-admin', {
            cwd: REPO_ROOT,
            stdio: 'pipe',
        });
    });

    test('returns JSON with reply, tool_trace, and links keys', async ({ request, browserName }) => {
        test.skip(browserName !== 'chromium', 'pure API test, no need to run per-browser');

        await loginAsAdmin(request);

        const response = await request.post(MESSAGE_ENDPOINT, {
            data: {
                message: 'What is the current system status?',
                history: [],
            },
        });

        // Accept 200 (success) or 500 (LLM unavailable) — both prove the
        // controller responded. A 401/403/404 would mean routing or ACL is
        // broken.
        const status = response.status();
        expect([200, 500]).toContain(status);

        const body = await response.json();

        if (status === 200) {
            expect(body).toHaveProperty('reply');
            expect(body).toHaveProperty('tool_trace');
            expect(body).toHaveProperty('links');
            expect(typeof body.reply).toBe('string');
            expect(body.reply.length).toBeGreaterThan(0);
        } else {
            // 500 means the agent threw (e.g. no API key configured).
            // The controller wraps it in {error: "..."}.
            expect(body).toHaveProperty('error');
        }
    });

    test('unauthenticated request is rejected', async ({ request, browserName }) => {
        test.skip(browserName !== 'chromium', 'pure API test, no need to run per-browser');

        // Do NOT call loginAsAdmin — send a raw unauthenticated request.
        const response = await request.post(MESSAGE_ENDPOINT, {
            data: {
                message: 'Hello',
                history: [],
            },
        });

        // Oro redirects unauthenticated admin requests to the login page
        // (302 -> 200 on the login form) or returns 401/403 depending on
        // firewall config. Any of these is acceptable; a 200 with valid
        // JSON chat response is NOT.
        const status = response.status();
        const url = response.url();

        // If we got redirected to login, that counts as "rejected".
        const isRedirectedToLogin = url.includes('/user/login');

        // Or we got an explicit 401/403.
        const isExplicitReject = status === 401 || status === 403;

        // If the response is 200 but it's the login page HTML, that's also
        // a valid rejection.
        let isLoginPageHtml = false;
        if (status === 200 && !isRedirectedToLogin) {
            const contentType = response.headers()['content-type'] || '';
            isLoginPageHtml = contentType.includes('text/html');
        }

        expect(isRedirectedToLogin || isExplicitReject || isLoginPageHtml).toBeTruthy();
    });

    test('empty message returns 400', async ({ request, browserName }) => {
        test.skip(browserName !== 'chromium', 'pure API test, no need to run per-browser');

        await loginAsAdmin(request);

        const response = await request.post(MESSAGE_ENDPOINT, {
            data: {
                message: '',
                history: [],
            },
        });

        expect(response.status()).toBe(400);

        const body = await response.json();
        expect(body).toHaveProperty('error');
        expect(body.error).toContain('Message is required');
    });
});
