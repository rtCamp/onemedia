/**
 * External dependencies
 */
import { defineConfig, type PlaywrightTestConfig } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const artifactsPath = path.join(process.cwd(), 'tests/_output/e2e');
const wpEnvTestConfigPath = path.join(process.cwd(), '.wp-env.test.json');
const wpEnvTestConfig = JSON.parse(
	fs.readFileSync(wpEnvTestConfigPath, 'utf8')
) as {
	port?: number;
};
const wpBaseUrl =
	process.env['WP_BASE_URL'] ||
	`http://localhost:${String(wpEnvTestConfig.port ?? 8889)}`;

process.env['WP_BASE_URL'] = wpBaseUrl;
process.env['WP_ARTIFACTS_PATH'] = artifactsPath;
process.env['STORAGE_STATE_PATH'] = path.join(
	artifactsPath,
	'storage-states',
	'admin.json'
);

// eslint-disable-next-line @typescript-eslint/no-var-requires
const baseConfig =
	require('@wordpress/scripts/config/playwright.config.js') as PlaywrightTestConfig;

const config = defineConfig({
	...baseConfig,
	testDir: './tests/e2e',
	outputDir: './tests/_output/e2e',
});

export default config;
