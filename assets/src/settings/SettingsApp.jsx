/**
 * Settings page React app — one screen, tabbed sections, REST-backed save.
 *
 * @package
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	getSettings,
	saveSettings,
	testConnection,
	fetchIntegrations,
} from './api';

const TABS = [
	{ key: 'general', label: 'General' },
	{ key: 'voice', label: 'Brand voice' },
	{ key: 'ai', label: 'AI provider' },
	{ key: 'backend', label: 'Backend' },
];

const HASHTAG_STRATEGIES = ['sparse', 'moderate', 'heavy'];
const POST_TYPES = ['now', 'schedule', 'draft'];

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
 * Main settings app.
 *
 * @param {Object} props
 * @param {Object} props.bootstrapData PHP-localised bootstrap data.
 * @return {Element} React element.
 */
export default function SettingsApp({ bootstrapData }) {
	const platforms = bootstrapData.platforms || [];

	const [tab, setTab] = useState('general');
	const [settings, setSettings] = useState(null);
	const [pendingKeys, setPendingKeys] = useState({
		anthropic: '',
		postiz: '',
	});
	const [saving, setSaving] = useState(false);
	const [savedAt, setSavedAt] = useState(null);
	const [error, setError] = useState(null);
	const [testResult, setTestResult] = useState({});
	const [integrations, setIntegrations] = useState([]);
	const [newAvoid, setNewAvoid] = useState('');

	useEffect(() => {
		getSettings()
			.then(setSettings)
			.catch((e) => setError(e.message));
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
		if (pendingKeys.postiz) {
			body.backend = body.backend || {};
			body.backend.postiz = body.backend.postiz || {};
			body.backend.postiz.api_key = pendingKeys.postiz;
		}
		if (body.ai_provider?.anthropic) {
			delete body.ai_provider.anthropic.api_key_set;
		}
		if (body.backend?.postiz) {
			delete body.backend.postiz.api_key_set;
		}

		try {
			const updated = await saveSettings(body);
			setSettings(updated);
			setPendingKeys({ anthropic: '', postiz: '' });
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, [settings, pendingKeys]);

	const runTest = useCallback(async (which) => {
		setTestResult((prev) => ({
			...prev,
			[which]: { loading: true },
		}));
		try {
			const result = await testConnection(which);
			setTestResult((prev) => ({ ...prev, [which]: result }));
		} catch (e) {
			setTestResult((prev) => ({
				...prev,
				[which]: { success: false, message: e.message },
			}));
		}
	}, []);

	const loadIntegrations = useCallback(async () => {
		try {
			const result = await fetchIntegrations();
			setIntegrations(result.integrations || []);
		} catch (e) {
			setError(e.message);
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

			{tab === 'backend' && (
				<>
					<table className="form-table">
						<tbody>
							<tr>
								<th>
									<label htmlFor="apex-cast-postiz-key">
										{__('Postiz API key', 'apex-cast')}
									</label>
								</th>
								<td>
									<input
										id="apex-cast-postiz-key"
										type="password"
										className="regular-text"
										placeholder={
											settings.backend?.postiz
												?.api_key_set
												? __(
														'•••••••• (saved)',
														'apex-cast'
													)
												: __(
														'Postiz API key',
														'apex-cast'
													)
										}
										value={pendingKeys.postiz}
										onChange={(e) =>
											setPendingKeys({
												...pendingKeys,
												postiz: e.target.value,
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
									<label htmlFor="apex-cast-postiz-url">
										{__('API URL', 'apex-cast')}
									</label>
								</th>
								<td>
									<input
										id="apex-cast-postiz-url"
										type="text"
										className="regular-text"
										value={
											settings.backend?.postiz?.api_url ||
											''
										}
										onChange={(e) =>
											update(
												'backend.postiz.api_url',
												e.target.value
											)
										}
									/>
								</td>
							</tr>
							<tr>
								<th>
									<label htmlFor="apex-cast-postiz-post-type">
										{__('Default post type', 'apex-cast')}
									</label>
								</th>
								<td>
									<select
										id="apex-cast-postiz-post-type"
										value={
											settings.backend?.postiz
												?.default_post_type || 'draft'
										}
										onChange={(e) =>
											update(
												'backend.postiz.default_post_type',
												e.target.value
											)
										}
									>
										{POST_TYPES.map((type) => (
											<option key={type} value={type}>
												{type}
											</option>
										))}
									</select>
								</td>
							</tr>
							<tr>
								<th />
								<td>
									<button
										type="button"
										className="button"
										onClick={() => runTest('backend')}
									>
										{__('Test connection', 'apex-cast')}
									</button>
									{renderTestResult('backend')}{' '}
									<button
										type="button"
										className="button"
										onClick={loadIntegrations}
									>
										{__('Load integrations', 'apex-cast')}
									</button>
								</td>
							</tr>
						</tbody>
					</table>

					{integrations.length > 0 && (
						<>
							<h3>
								{__(
									'Platform → integration mapping',
									'apex-cast'
								)}
							</h3>
							<table className="form-table">
								<tbody>
									{platforms.map((platform) => (
										<tr key={platform}>
											<th>{platform}</th>
											<td>
												<select
													value={
														settings.backend?.postiz
															?.integration_map?.[
															platform
														] || ''
													}
													onChange={(e) =>
														update(
															`backend.postiz.integration_map.${platform}`,
															e.target.value
														)
													}
												>
													<option value="">
														—{' '}
														{__(
															'Not configured',
															'apex-cast'
														)}{' '}
														—
													</option>
													{integrations
														.filter(
															(i) =>
																i.platform ===
																platform
														)
														.map((i) => (
															<option
																key={i.id}
																value={i.id}
															>
																{i.name || i.id}
															</option>
														))}
												</select>
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</>
					)}
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
