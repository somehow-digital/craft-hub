import { defineConfig } from 'void/config';

export default defineConfig({
	cloudflare: {
		compatibility_date: '2026-11-01',
		name: 'craft-hub',
		routes: [{ custom_domain: true, pattern: 'craft-hub.somehow-digital.dev' }],
	},
});
