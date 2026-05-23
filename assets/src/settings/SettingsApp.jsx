/**
 * Settings page React app — one screen, tabbed sections, REST-backed save.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getSettings, saveSettings, testConnection, startOAuth } from './api';

const TABS = [
	{ key: 'general', label: 'General' },
	{ key: 'voice', label: 'Brand voice' },
	{ key: 'ai', label: 'AI provider' },
	{ key: 'platforms', label: 'Platforms' },
];

const HASHTAG_STRATEGIES = ['sparse', 'moderate', 'heavy'];

// The full set of platforms the AI provider can generate for. Each row in the
// Platforms tab reflects the state of one of these. Order matches what the
// metabox shows.
const PLATFORMS = [
	{ id: 'facebook', label: 'Facebook' },
	{ id: 'instagram', label: 'Instagram' },
	{ id: 'pinterest', label: 'Pinterest' },
	{ id: 'x', label: 'X' },
	{ id: 'reddit', label: 'Reddit' },
];

/**
 * Deep-clone a JSON-shaped settings tree (no functions, no cycles).
 *
 * @param {Object} settings Settings tree to clone.
 * @return {Object} A deep copy of the input.
 */
function cloneSettings(settings) {
	return JSON.parse(JSON.stringify(settings));
}

/**
 * Apply a dot-path update, returning a new settings object.
 *
 * @param {Object} settings Settings tree to update.
 * @param {string} path     Dot-separated path (e.g. "store.name").
 * @param {*}      value    New value to write at the path.
 * @return {Object} A new settings tree with the value applied.
 */
function setPath(settings, path, value) {
	const next = cloneSettings(settings);
	const parts = path.split('.');
	let cursor = next;
	for (let i = 0; i < parts.length - 1; i++) {
		if (typeof cursor[parts[i]] !== 'object' || cursor[parts[i]] === null) {
			cursor[parts[i]] = {};
		}
		cursor = cursor[parts[i]];
	}
	cursor[parts[parts.length - 1]] = value;
	return next;
}

/**
 * Render the placeholder row for a platform whose publisher hasn't shipped yet.
 *
 * @return {Element} React element.
 */
function renderPlatformPlaceholder() {
	return (
		<span className="apex-cast-test-result failure">
			{__('Not yet implemented — coming in a later phase.', 'apex-cast')}
		</span>
	);
}

/**
 * Render the Pinterest configuration row in the Platforms tab.
 *
 * Two states based on whether an access token is already stored:
 *   - Connected: editable board id, Test connection, Disconnect
 *   - Disconnected: "Connect Pinterest" button (kicks off OAuth) + editable board id
 *
 * @param {Object}   args                     Render arguments.
 * @param {Object}   args.settings            Current settings tree.
 * @param {Function} args.update              Dot-path settings updater.
 * @param {Function} args.runTest             Test-connection callback (takes target id).
 * @param {Function} args.disconnectPinterest Disconnect callback.
 * @param {Function} args.renderTestResult    Renders the saved test-connection result.
 * @param {Function} args.onStartConnect      Click handler for the "Connect Pinterest" button.
 * @return {Element} React element.
 */
function renderPinterestRow({
	settings,
	update,
	runTest,
	disconnectPinterest,
	renderTestResult,
	onStartConnect,
}) {
	const pinterest = settings.platforms?.pinterest || {};
	const tokenSet = pinterest.access_token_set === true;

	if (tokenSet) {
		return (
			<>
				<p>
					<span className="apex-cast-test-result success">
						{__('Connected to Pinterest.', 'apex-cast')}
					</span>
				</p>
				<p>
					<label htmlFor="apex-cast-pinterest-board">
						{__('Board ID:', 'apex-cast')}{' '}
					</label>
					<input
						id="apex-cast-pinterest-board"
						type="text"
						className="regular-text"
						value={pinterest.board_id || ''}
						onChange={(e) =>
							update(
								'platforms.pinterest.board_id',
								e.target.value
							)
						}
					/>
				</p>
				<p>
					<button
						type="button"
						className="button"
						onClick={() => runTest('pinterest')}
					>
						{__('Test connection', 'apex-cast')}
					</button>
					{renderTestResult('pinterest')}{' '}
					<button
						type="button"
						className="button"
						onClick={disconnectPinterest}
					>
						{__('Disconnect', 'apex-cast')}
					</button>
				</p>
			</>
		);
	}

	return (
		<>
			<p>
				<button
					type="button"
					className="button button-primary"
					onClick={onStartConnect}
				>
					{__('Connect Pinterest', 'apex-cast')}
				</button>
			</p>
			<p>
				<label htmlFor="apex-cast-pinterest-board-new">
					{__('Board ID:', 'apex-cast')}
				</label>
				<br />
				<input
					id="apex-cast-pinterest-board-new"
					type="text"
					className="regular-text"
					value={pinterest.board_id || ''}
					onChange={(e) =>
						update('platforms.pinterest.board_id', e.target.value)
					}
				/>
			</p>
			<p className="description">
				{__(
					"You'll be redirected to Pinterest to authorize Apex Cast. Enter the destination board ID (the numeric segment from the board's URL) before or after connecting.",
					'apex-cast'
				)}
			</p>
		</>
	);
}

/**
 * Main settings app.
 *
 * @param {Object} props
 * @param {Object} props.bootstrapData PHP-localised bootstrap data.
 * @return {Element} React element.
 */
export default function SettingsApp({ bootstrapData }) {
	const [tab, setTab] = useState('general');
	const [settings, setSettings] = useState(null);
	const [pendingKeys, setPendingKeys] = useState({
		anthropic: '',
	});
	const [saving, setSaving] = useState(false);
	const [savedAt, setSavedAt] = useState(null);
	const [error, setError] = useState(null);
	const [testResult, setTestResult] = useState({});
	const [newAvoid, setNewAvoid] = useState('');

	// Reserved for future per-platform connect flows; consumed by the heuristic above.
	void bootstrapData;

	useEffect(() => {
		getSettings()
			.then(setSettings)
			.catch((e) => setError(e.message));
	}, []);

	// Detect ?apex_cast_oauth=... in the URL after Pinterest redirects us
	// back from the OAuth callback. Show a success/error notice, jump to the
	// Platforms tab, refresh settings, and scrub the params from the URL so a
	// page reload doesn't fire the toast a second time.
	useEffect(() => {
		const params = new URLSearchParams(window.location.search);
		const result = params.get('apex_cast_oauth');
		const platform = params.get('platform') || '';
		if (!result) {
			return;
		}

		if (result === 'success') {
			setSavedAt(Date.now());
			setTab('platforms');
			getSettings()
				.then(setSettings)
				.catch((e) => setError(e.message));
		} else {
			setError(
				`OAuth for ${platform || 'platform'} failed (${result}). Please try again.`
			);
			setTab('platforms');
		}

		params.delete('apex_cast_oauth');
		params.delete('platform');
		const newSearch = params.toString();
		window.history.replaceState(
			{},
			'',
			window.location.pathname + (newSearch ? '?' + newSearch : '')
		);
	}, []);

	/**
	 * Kick off the Pinterest OAuth flow: ask the server for an auth URL, then
	 * navigate the browser to it. The user comes back to this same settings
	 * page via the callback handler's redirect.
	 *
	 * @return {Promise<void>}
	 */
	const startPinterestConnect = useCallback(async () => {
		setError(null);
		try {
			const result = await startOAuth('pinterest');
			if (result && result.auth_url) {
				window.location.href = result.auth_url;
			} else {
				setError('Server did not return an auth URL.');
			}
		} catch (e) {
			setError(e.message);
		}
	}, []);

	const update = useCallback((path, value) => {
		setSettings((prev) => setPath(prev, path, value));
		setSavedAt(null);
	}, []);

	const handleSave = useCallback(async () => {
		if (!settings) {
			return;
		}
		setSaving(true);
		setError(null);

		const body = cloneSettings(settings);
		if (pendingKeys.anthropic) {
			body.ai_provider = body.ai_provider || {};
			body.ai_provider.anthropic = body.ai_provider.anthropic || {};
			body.ai_provider.anthropic.api_key = pendingKeys.anthropic;
		}
		if (body.ai_provider?.anthropic) {
			delete body.ai_provider.anthropic.api_key_set;
		}

		// Strip read-only redacted fields the server returns so we don't echo them back.
		if (body.platforms) {
			Object.keys(body.platforms).forEach((platformId) => {
				const platform = body.platforms[platformId];
				if (platform && typeof platform === 'object') {
					Object.keys(platform).forEach((key) => {
						if (key.endsWith('_set')) {
							delete platform[key];
						}
					});
				}
			});
		}

		try {
			const updated = await saveSettings(body);
			setSettings(updated);
			setPendingKeys({ anthropic: '' });
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, [settings, pendingKeys]);

	/**
	 * Clear the Pinterest credentials by saving an explicit `access_token: null`.
	 *
	 * @return {Promise<void>}
	 */
	const disconnectPinterest = useCallback(async () => {
		setSaving(true);
		setError(null);
		try {
			const updated = await saveSettings({
				platforms: { pinterest: { access_token: null } },
			});
			setSettings(updated);
			setTestResult((prev) => {
				const { pinterest, ...rest } = prev;
				void pinterest;
				return rest;
			});
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, []);

	const runTest = useCallback(async (target) => {
		setTestResult((prev) => ({
			...prev,
			[target]: { loading: true },
		}));
		try {
			const result = await testConnection(target);
			setTestResult((prev) => ({ ...prev, [target]: result }));
		} catch (e) {
			setTestResult((prev) => ({
				...prev,
				[target]: { success: false, message: e.message },
			}));
		}
	}, []);

	const addAvoid = useCallback(() => {
		const value = newAvoid.trim();
		if (!value) {
			return;
		}
		const current = settings?.brand_voice?.do_not_use || [];
		update('brand_voice.do_not_use', [...current, value]);
		setNewAvoid('');
	}, [newAvoid, settings, update]);

	const removeAvoid = useCallback(
		(idx) => {
			const current = settings?.brand_voice?.do_not_use || [];
			update(
				'brand_voice.do_not_use',
				current.filter((_, i) => i !== idx)
			);
		},
		[settings, update]
	);

	if (!settings) {
		return <p>{error || __('Loading settings…', 'apex-cast')}</p>;
	}

	const renderTestResult = (which) => {
		const result = testResult[which];
		if (!result) {
			return null;
		}
		if (result.loading) {
			return (
				<span className="apex-cast-test-result">
					{__('Testing…', 'apex-cast')}
				</span>
			);
		}
		return (
			<span
				className={`apex-cast-test-result ${
					result.success ? 'success' : 'failure'
				}`}
			>
				{result.message}
			</span>
		);
	};

	return (
		<div className="apex-cast-settings">
			<h2 className="nav-tab-wrapper">
				{TABS.map((t) => (
					<button
						key={t.key}
						type="button"
						className={`nav-tab ${
							tab === t.key ? 'nav-tab-active' : ''
						}`}
						onClick={() => setTab(t.key)}
					>
						{t.label}
					</button>
				))}
			</h2>

			{error && (
				<div className="notice notice-error">
					<p>{error}</p>
				</div>
			)}
			{savedAt && (
				<div className="notice notice-success">
					<p>{__('Settings saved.', 'apex-cast')}</p>
				</div>
			)}

			{tab === 'general' && (
				<table className="form-table">
					<tbody>
						<tr>
							<th>
								<label htmlFor="apex-cast-store-name">
									{__('Store name', 'apex-cast')}
								</label>
							</th>
							<td>
								<input
									id="apex-cast-store-name"
									type="text"
									className="regular-text"
									value={settings.store?.name || ''}
									onChange={(e) =>
										update('store.name', e.target.value)
									}
								/>
							</td>
						</tr>
						<tr>
							<th>
								<label htmlFor="apex-cast-store-description">
									{__('Store description', 'apex-cast')}
								</label>
							</th>
							<td>
								<textarea
									id="apex-cast-store-description"
									className="large-text"
									rows={3}
									value={settings.store?.description || ''}
									onChange={(e) =>
										update(
											'store.description',
											e.target.value
										)
									}
								/>
								<p className="description">
									{__(
										'Fed verbatim into the AI system prompt — keep it short and product-led.',
										'apex-cast'
									)}
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			)}

			{tab === 'voice' && (
				<table className="form-table">
					<tbody>
						<tr>
							<th>
								<label htmlFor="apex-cast-tone">
									{__('Tone', 'apex-cast')}
								</label>
							</th>
							<td>
								<input
									id="apex-cast-tone"
									type="text"
									className="regular-text"
									placeholder={__(
										'friendly, expert, playful…',
										'apex-cast'
									)}
									value={settings.brand_voice?.tone || ''}
									onChange={(e) =>
										update(
											'brand_voice.tone',
											e.target.value
										)
									}
								/>
							</td>
						</tr>
						<tr>
							<th>
								<label htmlFor="apex-cast-voice-notes">
									{__('Voice notes', 'apex-cast')}
								</label>
							</th>
							<td>
								<textarea
									id="apex-cast-voice-notes"
									className="large-text"
									rows={4}
									value={
										settings.brand_voice?.voice_notes || ''
									}
									onChange={(e) =>
										update(
											'brand_voice.voice_notes',
											e.target.value
										)
									}
								/>
							</td>
						</tr>
						<tr>
							<th>
								<label htmlFor="apex-cast-hashtag-strategy">
									{__('Hashtag strategy', 'apex-cast')}
								</label>
							</th>
							<td>
								<select
									id="apex-cast-hashtag-strategy"
									value={
										settings.brand_voice
											?.hashtag_strategy || 'moderate'
									}
									onChange={(e) =>
										update(
											'brand_voice.hashtag_strategy',
											e.target.value
										)
									}
								>
									{HASHTAG_STRATEGIES.map((strategy) => (
										<option key={strategy} value={strategy}>
											{strategy}
										</option>
									))}
								</select>
							</td>
						</tr>
						<tr>
							<th>{__('Avoid these phrases', 'apex-cast')}</th>
							<td>
								<div className="apex-cast-do-not-use-input">
									<input
										type="text"
										className="regular-text"
										value={newAvoid}
										onChange={(e) =>
											setNewAvoid(e.target.value)
										}
										onKeyDown={(e) => {
											if (e.key === 'Enter') {
												e.preventDefault();
												addAvoid();
											}
										}}
										placeholder={__(
											'limited time, cheap…',
											'apex-cast'
										)}
									/>
									<button
										type="button"
										className="button"
										onClick={addAvoid}
									>
										{__('Add', 'apex-cast')}
									</button>
								</div>
								<div className="apex-cast-tag-list">
									{(
										settings.brand_voice?.do_not_use || []
									).map((phrase, idx) => (
										<span
											key={`${phrase}-${idx}`}
											className="apex-cast-tag"
										>
											{phrase}
											<button
												type="button"
												aria-label={__(
													'Remove',
													'apex-cast'
												)}
												onClick={() => removeAvoid(idx)}
											>
												×
											</button>
										</span>
									))}
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			)}

			{tab === 'ai' && (
				<table className="form-table">
					<tbody>
						<tr>
							<th>
								<label htmlFor="apex-cast-anthropic-key">
									{__('Anthropic API key', 'apex-cast')}
								</label>
							</th>
							<td>
								<input
									id="apex-cast-anthropic-key"
									type="password"
									className="regular-text"
									placeholder={
										settings.ai_provider?.anthropic
											?.api_key_set
											? __(
													'•••••••• (saved)',
													'apex-cast'
												)
											: 'sk-ant-…'
									}
									value={pendingKeys.anthropic}
									onChange={(e) =>
										setPendingKeys({
											...pendingKeys,
											anthropic: e.target.value,
										})
									}
								/>
								<p className="description">
									{__(
										'Leave blank to keep the existing key.',
										'apex-cast'
									)}
								</p>
							</td>
						</tr>
						<tr>
							<th>
								<label htmlFor="apex-cast-anthropic-model">
									{__('Model', 'apex-cast')}
								</label>
							</th>
							<td>
								<input
									id="apex-cast-anthropic-model"
									type="text"
									className="regular-text"
									value={
										settings.ai_provider?.anthropic
											?.model || ''
									}
									onChange={(e) =>
										update(
											'ai_provider.anthropic.model',
											e.target.value
										)
									}
								/>
							</td>
						</tr>
						<tr>
							<th>
								<label htmlFor="apex-cast-anthropic-max-tokens">
									{__('Max tokens', 'apex-cast')}
								</label>
							</th>
							<td>
								<input
									id="apex-cast-anthropic-max-tokens"
									type="number"
									min="1"
									value={
										settings.ai_provider?.anthropic
											?.max_tokens || 1024
									}
									onChange={(e) =>
										update(
											'ai_provider.anthropic.max_tokens',
											parseInt(e.target.value, 10) || 1024
										)
									}
								/>
							</td>
						</tr>
						<tr>
							<th />
							<td>
								<button
									type="button"
									className="button"
									onClick={() => runTest('ai')}
								>
									{__('Test connection', 'apex-cast')}
								</button>
								{renderTestResult('ai')}
							</td>
						</tr>
					</tbody>
				</table>
			)}

			{tab === 'platforms' && (
				<>
					<p className="description">
						{__(
							'Each platform has its own connection flow. Pinterest, X, and Reddit work with personal accounts; Facebook + Instagram require a Page + Creator account. Connection UI lands per-platform in upcoming phases.',
							'apex-cast'
						)}
					</p>
					<table className="form-table">
						<tbody>
							{PLATFORMS.map((p) => (
								<tr key={p.id}>
									<th>{p.label}</th>
									<td>
										{p.id === 'pinterest'
											? renderPinterestRow({
													settings,
													update,
													runTest,
													disconnectPinterest,
													renderTestResult,
													onStartConnect:
														startPinterestConnect,
												})
											: renderPlatformPlaceholder()}
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</>
			)}

			<p className="submit">
				<button
					type="button"
					className="button button-primary"
					onClick={handleSave}
					disabled={saving}
				>
					{saving
						? __('Saving…', 'apex-cast')
						: __('Save settings', 'apex-cast')}
				</button>
			</p>
		</div>
	);
}
