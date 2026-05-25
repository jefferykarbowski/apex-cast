/**
 * Thin fetch wrapper for the metabox-side REST calls.
 *
 * Reads `window.APEX_CAST_DATA` for `restUrl` and `nonce` at call time so unit
 * tests that load this module without a `window` global can still import it.
 */

/**
 * Resolve the runtime bootstrap data, falling back to an empty object so
 * imports never throw during test runs that don't shim `window`.
 *
 * @return {{ restUrl?: string, nonce?: string }} The localised bootstrap object, or an empty fallback.
 */
function bootstrap() {
	if (typeof window === 'undefined' || !window.APEX_CAST_DATA) {
		return {};
	}
	return window.APEX_CAST_DATA;
}

/**
 * Perform a JSON request against the Apex Cast REST namespace and unwrap the
 * `{ ok, data, error }` envelope.
 *
 * @param {string}  method HTTP method.
 * @param {string}  path   Path under the REST namespace (e.g. "/send").
 * @param {?Object} body   Optional JSON-encodable body.
 * @return {Promise<*>} Resolves to `data`; rejects with the envelope's error message.
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

/**
 * Broadcast a product to the selected platforms.
 *
 * @param {number}   productId         WooCommerce product ID.
 * @param {string[]} platforms         Platform identifiers to send to.
 * @param {Object}   [platformOptions] Optional per-platform options (e.g. Pinterest board override).
 * @return {Promise<Object>} The send result envelope's data payload.
 */
export function sendProduct(productId, platforms, platformOptions) {
	const body = {
		product_id: productId,
		platforms,
	};
	if (platformOptions && typeof platformOptions === 'object') {
		body.platform_options = platformOptions;
	}
	return request('POST', '/send', body);
}

/**
 * List every Pinterest board the connected account owns. Used by the metabox
 * "Pin to" override picker.
 *
 * @return {Promise<{boards: Array<{id: string, name: string, privacy: string}>}>}
 *   Resolves to the data payload from the envelope.
 */
export function listPinterestBoards() {
	return request('GET', '/pinterest/boards');
}
