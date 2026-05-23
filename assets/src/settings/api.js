/**
 * Thin fetch wrapper for the settings-page REST calls.
 *
 * @package
 */

/**
 * Resolve the runtime bootstrap data, falling back to an empty object so
 * imports never throw during test runs.
 *
 * @return {{ restUrl?: string, nonce?: string }} The localised bootstrap object, or an empty fallback.
 */
function bootstrap() {
	if (typeof window === 'undefined' || !window.APEX_CAST_SETTINGS_DATA) {
		return {};
	}
	return window.APEX_CAST_SETTINGS_DATA;
}

/**
 * Perform a JSON request against the Apex Cast REST namespace.
 *
 * @param {string}  method HTTP method.
 * @param {string}  path   Path under the REST namespace.
 * @param {?Object} body   Optional JSON-encodable body.
 * @return {Promise<*>} Resolves to the `data` payload from the envelope.
 */
async function request(method, path, body) {
	const data = bootstrap();
	const response = await fetch(`${data.restUrl || ''}${path}`, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': data.nonce || '',
		},
		body: body ? JSON.stringify(body) : undefined,
	});

	let payload;
	try {
		payload = await response.json();
	} catch (e) {
		throw new Error(`Server returned HTTP ${response.status}.`);
	}

	if (!payload || payload.ok !== true) {
		const message =
			(payload && payload.error && payload.error.message) ||
			`Server returned HTTP ${response.status}.`;
		throw new Error(message);
	}

	return payload.data;
}

export function getSettings() {
	return request('GET', '/settings');
}

export function saveSettings(body) {
	return request('POST', '/settings', body);
}

export function testConnection(target) {
	return request('POST', '/test-connection', {
		target,
	});
}

/**
 * Initiate an OAuth flow for a platform; the server returns the `auth_url` the
 * browser should navigate to. The caller is responsible for the redirect.
 *
 * @param {string} platform Platform identifier ("pinterest").
 * @return {Promise<{auth_url: string}>} The data payload from the envelope.
 */
export function startOAuth(platform) {
	return request('POST', `/oauth/${platform}/start`);
}
