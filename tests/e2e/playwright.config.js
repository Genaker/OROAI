// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const SCHEME = process.env.ORO_TEST_HTTP_SCHEME || 'http';
const HOST = process.env.ORO_TEST_HTTP_HOST || 'localhost';
const PORT = process.env.ORO_TEST_HTTP_PORT || '8000';

/**
 * Standalone config for the OroAI Chat e2e suite — independent of the
 * repo-root `playwright.config.js`.
 */
module.exports = defineConfig({
    testDir: '.',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_TEST_BASE_URL || `${SCHEME}://${HOST}:${PORT}`,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
