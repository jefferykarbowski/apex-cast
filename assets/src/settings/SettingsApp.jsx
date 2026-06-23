/**
 * Settings page React app — one screen, tabbed sections, REST-backed save.
 */

import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	getSettings,
	saveSettings,
	testConnection,
	startOAuth,
	listPinterestBoards,
	searchWooCommerceTags,
} from './api';

// Sentinel select value meaning "auto-create a board on first send" rather
// than mapping to an existing board id.
const AUTO_CREATE_VALUE = '__auto_create__';

const TABS = [
	{ key: 'general', label: 'General' },
	{ key: 'voice', label: 'Brand voice' },
	{ key: 'platforms', label: 'Platforms' },
];

const HASHTAG_STRATEGIES = ['sparse', 'moderate', 'heavy'];

// The full set of platforms Apex Cast can broadcast to. Each row in the
// Platforms tab reflects the state of one of these. Order matches what the
// metabox shows.
const PLATFORMS = [
	{ id: 'facebook', label: 'Facebook' },
	{ id: 'instagram', label: 'Instagram' },
	{ id: 'pinterest', label: 'Pinterest' },
	{ id: 'bluesky', label: 'Bluesky' },
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
 * Render the Pinterest tag → board routing section.
 *
 * Two stacked areas:
 *   - Existing mappings table: every entry in `tag_board_map` (or
 *     `tag_auto_create`) gets a row showing the slug, a board picker, and a
 *     Remove button. Editing the picker writes back into the maps in place.
 *   - Add-mapping form: a tag-slug autocomplete + a board picker + Add button.
 *     On Add, the new entry is appended to `tag_board_map` (or
 *     `tag_auto_create` when the user picked "Auto-create on first send").
 *
 * Owns its own UI state (autocomplete query, suggestions, draft slug/board)
 * but reads + writes only via the dot-path `update()` helper for the canonical
 * settings tree.
 *
 * @param {Object}   args               Render arguments.
 * @param {Object}   args.pinterest     Pinterest settings sub-tree.
 * @param {Function} args.update        Dot-path settings updater.
 * @param {Array}    args.boards        Cached Pinterest boards list ([] while loading).
 * @param {boolean}  args.boardsLoading Boards request in-flight.
 * @param {?string}  args.boardsError   Boards request error (null when fine).
 * @return {Element} React element.
 */
function renderPinterestTagRouting({
	pinterest,
	update,
	boards,
	boardsLoading,
	boardsError,
}) {
	const tagBoardMap = pinterest.tag_board_map || {};
	const tagAutoCreate = pinterest.tag_auto_create || {};
	const slugs = Array.from(
		new Set([...Object.keys(tagBoardMap), ...Object.keys(tagAutoCreate)])
	).sort();

	// Local state lives on the parent via a closure trick: this is a child
	// render, so we keep state in component-local React hooks below in
	// `PinterestTagRouting` instead. Punt rendering to that component.
	return (
		<PinterestTagRouting
			tagBoardMap={tagBoardMap}
			tagAutoCreate={tagAutoCreate}
			slugs={slugs}
			boards={boards}
			boardsLoading={boardsLoading}
			boardsError={boardsError}
			update={update}
		/>
	);
}

/**
 * Self-contained tag → board routing component. Lives below the default-board
 * input on the Pinterest row when the platform is connected.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.tagBoardMap   Current tag → board id mapping.
 * @param {Object}   props.tagAutoCreate Current tag → auto-create flag mapping.
 * @param {string[]} props.slugs         Union of slugs from both maps, sorted.
 * @param {?Array}   props.boards        Cached Pinterest boards (null while loading).
 * @param {boolean}  props.boardsLoading Boards request in-flight.
 * @param {?string}  props.boardsError   Boards request error.
 * @param {Function} props.update        Dot-path settings updater.
 * @return {Element} React element.
 */
function PinterestTagRouting({
	tagBoardMap,
	tagAutoCreate,
	slugs,
	boards,
	boardsLoading,
	boardsError,
	update,
}) {
	const [query, setQuery] = useState('');
	const [suggestions, setSuggestions] = useState([]);
	const [suggestionsLoading, setSuggestionsLoading] = useState(false);
	const [draftSlug, setDraftSlug] = useState('');
	const [draftBoard, setDraftBoard] = useState('');
	const [addError, setAddError] = useState('');

	// Debounce the autocomplete query — wait 200ms after the last keystroke
	// before hitting the server.
	useEffect(() => {
		if (query.trim().length < 2) {
			setSuggestions([]);
			return undefined;
		}
		setSuggestionsLoading(true);
		const handle = setTimeout(() => {
			searchWooCommerceTags(query.trim(), 10)
				.then((data) => {
					setSuggestions(Array.isArray(data?.tags) ? data.tags : []);
				})
				.catch(() => setSuggestions([]))
				.finally(() => setSuggestionsLoading(false));
		}, 200);
		return () => clearTimeout(handle);
	}, [query]);

	const sortedBoards = useMemo(() => {
		if (!Array.isArray(boards)) {
			return [];
		}
		return [...boards].sort((a, b) =>
			String(a.name || '').localeCompare(String(b.name || ''))
		);
	}, [boards]);

	/**
	 * Compute the canonical select value for an existing mapping row.
	 *
	 * @param {string} slug Tag slug.
	 * @return {string} Select value (board id, AUTO_CREATE_VALUE, or "").
	 */
	const valueForSlug = (slug) => {
		if (tagBoardMap[slug]) {
			return String(tagBoardMap[slug]);
		}
		if (tagAutoCreate[slug] === true) {
			return AUTO_CREATE_VALUE;
		}
		return '';
	};

	/**
	 * Apply a select change to the canonical settings tree.
	 *
	 * @param {string} slug  Tag slug.
	 * @param {string} value Select value.
	 */
	const writeForSlug = (slug, value) => {
		const nextMap = { ...tagBoardMap };
		const nextAuto = { ...tagAutoCreate };
		if (value === AUTO_CREATE_VALUE) {
			delete nextMap[slug];
			nextAuto[slug] = true;
		} else if (value === '') {
			delete nextMap[slug];
			delete nextAuto[slug];
		} else {
			nextMap[slug] = value;
			delete nextAuto[slug];
		}
		update('platforms.pinterest.tag_board_map', nextMap);
		update('platforms.pinterest.tag_auto_create', nextAuto);
	};

	/**
	 * Remove every trace of a slug from both maps.
	 *
	 * @param {string} slug Tag slug.
	 */
	const removeSlug = (slug) => {
		const nextMap = { ...tagBoardMap };
		const nextAuto = { ...tagAutoCreate };
		delete nextMap[slug];
		delete nextAuto[slug];
		update('platforms.pinterest.tag_board_map', nextMap);
		update('platforms.pinterest.tag_auto_create', nextAuto);
	};

	const handleAdd = () => {
		const slug = (draftSlug || query).trim();
		if (!slug) {
			setAddError(__('Pick a tag from the suggestions.', 'apex-cast'));
			return;
		}
		if (
			Object.prototype.hasOwnProperty.call(tagBoardMap, slug) ||
			Object.prototype.hasOwnProperty.call(tagAutoCreate, slug)
		) {
			setAddError(__('This tag is already mapped.', 'apex-cast'));
			return;
		}
		if (!draftBoard) {
			setAddError(__('Pick a board (or auto-create).', 'apex-cast'));
			return;
		}
		setAddError('');
		writeForSlug(slug, draftBoard);
		setQuery('');
		setDraftSlug('');
		setDraftBoard('');
		setSuggestions([]);
	};

	return (
		<div className="apex-cast-tag-routing">
			<h4>{__('Tag → Board routing', 'apex-cast')}</h4>
			<p className="description">
				{__(
					"Send pins to a specific board based on the product's tags. The first matching tag wins; products with no matching tag fall back to the default board above.",
					'apex-cast'
				)}
			</p>

			{boardsError && (
				<p className="apex-cast-tag-routing-error">{boardsError}</p>
			)}
			{boardsLoading && (
				<p className="description">
					{__('Loading boards…', 'apex-cast')}
				</p>
			)}

			{slugs.length > 0 && (
				<table className="widefat apex-cast-tag-routing-table">
					<thead>
						<tr>
							<th>{__('Tag', 'apex-cast')}</th>
							<th>{__('Board', 'apex-cast')}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{slugs.map((slug) => (
							<tr key={slug}>
								<td>
									<code>{slug}</code>
								</td>
								<td>
									<select
										value={valueForSlug(slug)}
										onChange={(e) =>
											writeForSlug(slug, e.target.value)
										}
									>
										<option value="">
											{__('— use default —', 'apex-cast')}
										</option>
										{sortedBoards.map((board) => (
											<option
												key={board.id}
												value={board.id}
											>
												{board.name}
											</option>
										))}
										<option value={AUTO_CREATE_VALUE}>
											{__(
												'Auto-create on first send',
												'apex-cast'
											)}
										</option>
									</select>
								</td>
								<td>
									<button
										type="button"
										className="button-link-delete"
										onClick={() => removeSlug(slug)}
									>
										{__('Remove', 'apex-cast')}
									</button>
								</td>
							</tr>
						))}
					</tbody>
				</table>
			)}

			<div className="apex-cast-tag-routing-add">
				<h5>{__('Add mapping', 'apex-cast')}</h5>
				<div className="apex-cast-tag-routing-add-row">
					<input
						type="text"
						className="regular-text"
						placeholder={__('Search product tags…', 'apex-cast')}
						value={query}
						onChange={(e) => {
							setQuery(e.target.value);
							setDraftSlug('');
						}}
					/>
					<select
						value={draftBoard}
						onChange={(e) => setDraftBoard(e.target.value)}
					>
						<option value="">
							{__('— pick a board —', 'apex-cast')}
						</option>
						{sortedBoards.map((board) => (
							<option key={board.id} value={board.id}>
								{board.name}
							</option>
						))}
						<option value={AUTO_CREATE_VALUE}>
							{__('Auto-create on first send', 'apex-cast')}
						</option>
					</select>
					<button
						type="button"
						className="button"
						onClick={handleAdd}
					>
						{__('Add', 'apex-cast')}
					</button>
				</div>
				{suggestionsLoading && (
					<p className="description">
						{__('Searching…', 'apex-cast')}
					</p>
				)}
				{!suggestionsLoading && suggestions.length > 0 && (
					<ul className="apex-cast-tag-routing-suggestions">
						{suggestions.map((tag) => (
							<li key={tag.slug}>
								<button
									type="button"
									className="button-link"
									onClick={() => {
										setDraftSlug(tag.slug);
										setQuery(tag.slug);
										setSuggestions([]);
									}}
								>
									{tag.name}{' '}
									<span className="apex-cast-tag-routing-suggestion-meta">
										<code>{tag.slug}</code> · {tag.count}
									</span>
								</button>
							</li>
						))}
					</ul>
				)}
				{addError && (
					<p className="apex-cast-tag-routing-error">{addError}</p>
				)}
			</div>
		</div>
	);
}

/**
 * Render the API mode <select> shared by the connected + disconnected states.
 *
 * Pinterest's trial-mode apps can't publish on production (HTTP 403 code 29),
 * so we let the user swap the entire Pinterest-facing realm over to
 * `api-sandbox.pinterest.com` for testing + the Standard Access demo-video
 * recording. Production and sandbox have separate token universes, so the
 * caller is responsible for nudging the user to disconnect + reconnect on a
 * mode change — surfaced via the inline warning when `currentMode` differs
 * from `savedMode`.
 *
 * @param {Object}   args             Render arguments.
 * @param {string}   args.idSuffix    Suffix appended to the `<select>` id to keep duplicates safe.
 * @param {string}   args.currentMode Draft mode currently in the settings tree.
 * @param {string}   args.savedMode   Mode that was on the server at last load/save.
 * @param {Function} args.update      Dot-path settings updater.
 * @return {Element} React element.
 */
function renderPinterestApiModeSelect({
	idSuffix,
	currentMode,
	savedMode,
	update,
}) {
	const showWarning = currentMode !== savedMode;
	return (
		<p className="apex-cast-pinterest-api-mode">
			<label htmlFor={`apex-cast-pinterest-api-mode-${idSuffix}`}>
				{__('API mode:', 'apex-cast')}{' '}
			</label>
			<select
				id={`apex-cast-pinterest-api-mode-${idSuffix}`}
				value={currentMode}
				onChange={(e) =>
					update('platforms.pinterest.api_mode', e.target.value)
				}
			>
				<option value="production">
					{__(
						'Production (after Pinterest Standard Access approval)',
						'apex-cast'
					)}
				</option>
				<option value="sandbox">
					{__(
						'Sandbox (trial-mode testing only — pins not publicly visible)',
						'apex-cast'
					)}
				</option>
			</select>
			{showWarning && (
				<span className="apex-cast-pinterest-api-mode-warning">
					{' '}
					{__(
						'⚠ Mode change requires disconnect + reconnect to get a new token.',
						'apex-cast'
					)}
				</span>
			)}
		</p>
	);
}

/**
 * Render the Pinterest configuration row in the Platforms tab.
 *
 * Two states based on whether an access token is already stored:
 *   - Connected: editable default board id, tag → board routing, Test connection, Disconnect
 *   - Disconnected: "Connect Pinterest" button (kicks off OAuth) + editable board id
 *
 * @param {Object}   args                     Render arguments.
 * @param {Object}   args.settings            Current settings tree.
 * @param {Function} args.update              Dot-path settings updater.
 * @param {Function} args.runTest             Test-connection callback (takes target id).
 * @param {Function} args.disconnectPinterest Disconnect callback.
 * @param {Function} args.renderTestResult    Renders the saved test-connection result.
 * @param {Function} args.onStartConnect      Click handler for the "Connect Pinterest" button.
 * @param {Array}    args.pinterestBoards     Cached boards list (null while not yet loaded).
 * @param {boolean}  args.boardsLoading       Boards request in-flight.
 * @param {?string}  args.boardsError         Boards request error.
 * @param {string}   args.savedApiMode        Pinterest API mode as of the last GET /settings or save.
 * @return {Element} React element.
 */
function renderPinterestRow({
	settings,
	update,
	runTest,
	disconnectPinterest,
	renderTestResult,
	onStartConnect,
	pinterestBoards,
	boardsLoading,
	boardsError,
	savedApiMode,
}) {
	const pinterest = settings.platforms?.pinterest || {};
	const tokenSet = pinterest.access_token_set === true;
	const currentApiMode = pinterest.api_mode || 'production';

	if (tokenSet) {
		return (
			<>
				<p>
					<span className="apex-cast-test-result success">
						{__('Connected to Pinterest.', 'apex-cast')}
					</span>
				</p>
				{renderPinterestApiModeSelect({
					idSuffix: 'connected',
					currentMode: currentApiMode,
					savedMode: savedApiMode,
					update,
				})}
				<p>
					<label htmlFor="apex-cast-pinterest-board">
						{__(
							'Default board — fallback when no category matches:',
							'apex-cast'
						)}{' '}
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
				{renderPinterestTagRouting({
					pinterest,
					update,
					boards: pinterestBoards,
					boardsLoading,
					boardsError,
				})}
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
			{renderPinterestApiModeSelect({
				idSuffix: 'disconnected',
				currentMode: currentApiMode,
				savedMode: savedApiMode,
				update,
			})}
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
 * Render the Facebook configuration row in the Platforms tab.
 *
 * One OAuth round-trip handles both Facebook *and* the linked Instagram, so
 * this row owns the Connect / Disconnect / Test controls. The Instagram row
 * is read-only and reflects whatever the FB OAuth flow captured.
 *
 * @param {Object}   args                    Render arguments.
 * @param {Object}   args.settings           Current settings tree.
 * @param {Function} args.runTest            Test-connection callback.
 * @param {Function} args.disconnectFacebook Disconnect callback.
 * @param {Function} args.renderTestResult   Renders the test-connection result.
 * @param {Function} args.onStartConnect     "Connect Facebook" click handler.
 * @return {Element} React element.
 */
function renderFacebookRow({
	settings,
	runTest,
	disconnectFacebook,
	renderTestResult,
	onStartConnect,
}) {
	const fb = settings.platforms?.facebook || {};
	const tokenSet = fb.page_access_token_set === true;

	if (tokenSet) {
		const pageName = fb.page_name || '';
		return (
			<>
				<p>
					<span className="apex-cast-test-result success">
						{pageName
							? `Connected to Facebook Page "${pageName}".`
							: __('Connected to Facebook.', 'apex-cast')}
					</span>
				</p>
				<p>
					<button
						type="button"
						className="button"
						onClick={() => runTest('facebook')}
					>
						{__('Test connection', 'apex-cast')}
					</button>
					{renderTestResult('facebook')}{' '}
					<button
						type="button"
						className="button"
						onClick={disconnectFacebook}
					>
						{__('Disconnect Facebook + Instagram', 'apex-cast')}
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
					{__('Connect Facebook', 'apex-cast')}
				</button>
			</p>
			<p className="description">
				{__(
					"Connects both the Facebook Page and the linked Instagram in one step. You'll authorize Apex Cast on Meta's consent screen.",
					'apex-cast'
				)}
			</p>
		</>
	);
}

/**
 * Render the Instagram row — read-only, reflects what the Meta OAuth captured.
 *
 * @param {Object}   args                  Render arguments.
 * @param {Object}   args.settings         Current settings tree.
 * @param {Function} args.runTest          Test-connection callback.
 * @param {Function} args.renderTestResult Renders the test-connection result.
 * @return {Element} React element.
 */
function renderInstagramRow({ settings, runTest, renderTestResult }) {
	const ig = settings.platforms?.instagram || {};
	const fb = settings.platforms?.facebook || {};
	const fbTokenSet = fb.page_access_token_set === true;
	const igTokenSet = ig.page_access_token_set === true;

	if (!fbTokenSet) {
		return (
			<span className="apex-cast-test-result failure">
				{__('Connect Facebook above to enable Instagram.', 'apex-cast')}
			</span>
		);
	}

	if (!igTokenSet || !ig.ig_business_account_id) {
		return (
			<span className="apex-cast-test-result failure">
				{__(
					'No Instagram Business / Creator account is linked to the connected Facebook Page.',
					'apex-cast'
				)}
			</span>
		);
	}

	const username = ig.username || '';

	return (
		<>
			<p>
				<span className="apex-cast-test-result success">
					{username
						? `Connected as @${username}.`
						: __('Connected to Instagram.', 'apex-cast')}
				</span>
			</p>
			<p>
				<button
					type="button"
					className="button"
					onClick={() => runTest('instagram')}
				>
					{__('Test connection', 'apex-cast')}
				</button>
				{renderTestResult('instagram')}
			</p>
		</>
	);
}

/**
 * Render the Bluesky row. Bluesky authenticates with a handle + app password
 * (no OAuth redirect), so this row is a plain form: a handle field, an
 * app-password field (the password is held in local pending state and only
 * sent when the user types a fresh one), Test connection, and Disconnect.
 *
 * @param {Object}   args                   Render arguments.
 * @param {Object}   args.settings          Current settings tree.
 * @param {Function} args.update            Dot-path settings updater.
 * @param {Function} args.runTest           Test-connection callback (takes target id).
 * @param {Function} args.disconnectBluesky Disconnect callback.
 * @param {Function} args.renderTestResult  Renders the saved test-connection result.
 * @param {string}   args.pendingPassword   Draft app password not yet saved.
 * @param {Function} args.onPendingPassword Setter for the draft app password.
 * @return {Element} React element.
 */
function renderBlueskyRow({
	settings,
	update,
	runTest,
	disconnectBluesky,
	renderTestResult,
	pendingPassword,
	onPendingPassword,
}) {
	const bluesky = settings.platforms?.bluesky || {};
	const passwordSet = bluesky.app_password_set === true;
	const handle = bluesky.handle || '';

	return (
		<>
			{passwordSet && handle && (
				<p>
					<span className="apex-cast-test-result success">
						{`Connected as @${handle}.`}
					</span>
				</p>
			)}
			<p>
				<label htmlFor="apex-cast-bluesky-handle">
					{__('Handle:', 'apex-cast')}
				</label>
				<br />
				<input
					id="apex-cast-bluesky-handle"
					type="text"
					className="regular-text"
					placeholder="viciousfun.bsky.social"
					value={handle}
					onChange={(e) =>
						update('platforms.bluesky.handle', e.target.value)
					}
				/>
			</p>
			<p>
				<label htmlFor="apex-cast-bluesky-app-password">
					{__('App password:', 'apex-cast')}
				</label>
				<br />
				<input
					id="apex-cast-bluesky-app-password"
					type="password"
					className="regular-text"
					autoComplete="new-password"
					placeholder={
						passwordSet ? '•••••••• (saved)' : 'xxxx-xxxx-xxxx-xxxx'
					}
					value={pendingPassword}
					onChange={(e) => onPendingPassword(e.target.value)}
				/>
			</p>
			<p className="description">
				{__(
					'Create an app password at Bluesky → Settings → App Passwords. This is not your account password.',
					'apex-cast'
				)}
			</p>
			<p>
				<button
					type="button"
					className="button"
					onClick={() => runTest('bluesky')}
				>
					{__('Test connection', 'apex-cast')}
				</button>
				{renderTestResult('bluesky')}{' '}
				{passwordSet && (
					<button
						type="button"
						className="button"
						onClick={disconnectBluesky}
					>
						{__('Disconnect', 'apex-cast')}
					</button>
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
	const [saving, setSaving] = useState(false);
	const [savedAt, setSavedAt] = useState(null);
	const [error, setError] = useState(null);
	const [testResult, setTestResult] = useState({});
	const [newAvoid, setNewAvoid] = useState('');
	const [pinterestBoards, setPinterestBoards] = useState(null);
	const [pinterestBoardsLoading, setPinterestBoardsLoading] = useState(false);
	const [pinterestBoardsError, setPinterestBoardsError] = useState(null);
	// Tracks the Pinterest API mode as of the last successful load/save so the
	// row can compare against the draft value in `settings` and surface the
	// "reconnect required" warning only when the user has actually changed it.
	const [savedPinterestApiMode, setSavedPinterestApiMode] =
		useState('production');
	// Draft Bluesky app password. Held locally (never round-tripped through the
	// settings tree, which only ever sees the `*_set` boolean) and folded into
	// the save body only when non-empty, mirroring the old anthropic key flow.
	const [pendingBlueskyPassword, setPendingBlueskyPassword] = useState('');

	// Reserved for future per-platform connect flows; consumed by the heuristic above.
	void bootstrapData;

	const pinterestTokenSet =
		settings?.platforms?.pinterest?.access_token_set === true;

	// Lazily fetch the Pinterest board list once we know we're connected. Used
	// by the tag-routing picker.
	useEffect(() => {
		if (
			!pinterestTokenSet ||
			pinterestBoards !== null ||
			pinterestBoardsLoading
		) {
			return;
		}
		setPinterestBoardsLoading(true);
		setPinterestBoardsError(null);
		listPinterestBoards()
			.then((data) => {
				const boards = Array.isArray(data?.boards) ? data.boards : [];
				setPinterestBoards(boards);
			})
			.catch((e) => {
				setPinterestBoardsError(e.message);
				setPinterestBoards([]);
			})
			.finally(() => setPinterestBoardsLoading(false));
	}, [pinterestTokenSet, pinterestBoards, pinterestBoardsLoading]);

	/**
	 * Apply a freshly-loaded settings tree from the server. Also snapshots the
	 * Pinterest API mode for the dirty-vs-saved comparison.
	 *
	 * @param {Object} next Settings tree from the server.
	 */
	const applyLoadedSettings = useCallback((next) => {
		setSettings(next);
		setSavedPinterestApiMode(
			next?.platforms?.pinterest?.api_mode || 'production'
		);
	}, []);

	useEffect(() => {
		getSettings()
			.then(applyLoadedSettings)
			.catch((e) => setError(e.message));
	}, [applyLoadedSettings]);

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
				.then(applyLoadedSettings)
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
	}, [applyLoadedSettings]);

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

	/**
	 * Kick off the Meta OAuth flow. One flow connects both the Facebook Page
	 * and the linked Instagram account.
	 *
	 * @return {Promise<void>}
	 */
	const startFacebookConnect = useCallback(async () => {
		setError(null);
		try {
			const result = await startOAuth('facebook');
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

		// Fold in the pending Bluesky app password as plaintext only when the
		// user typed a new one; the server encrypts it into
		// app_password_encrypted. An empty draft means "leave the saved one
		// alone".
		if (pendingBlueskyPassword) {
			if (!body.platforms) {
				body.platforms = {};
			}
			if (!body.platforms.bluesky) {
				body.platforms.bluesky = {};
			}
			body.platforms.bluesky.app_password = pendingBlueskyPassword;
		}

		try {
			const updated = await saveSettings(body);
			applyLoadedSettings(updated);
			setPendingBlueskyPassword('');
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, [settings, applyLoadedSettings, pendingBlueskyPassword]);

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
			applyLoadedSettings(updated);
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
	}, [applyLoadedSettings]);

	/**
	 * Clear the Facebook + Instagram credentials by saving explicit nulls for
	 * every secret field on both. The shared Page Access Token means both
	 * platforms disconnect together.
	 *
	 * @return {Promise<void>}
	 */
	const disconnectFacebook = useCallback(async () => {
		setSaving(true);
		setError(null);
		try {
			const updated = await saveSettings({
				platforms: {
					facebook: {
						user_access_token: null,
						page_access_token: null,
						page_id: '',
						page_name: '',
					},
					instagram: {
						page_access_token: null,
						ig_business_account_id: '',
						username: '',
					},
				},
			});
			applyLoadedSettings(updated);
			setTestResult((prev) => {
				const { facebook, instagram, ...rest } = prev;
				void facebook;
				void instagram;
				return rest;
			});
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, [applyLoadedSettings]);

	/**
	 * Clear the Bluesky credentials: null the app password (clears the
	 * ciphertext) and blank the handle. Also drops any pending draft password.
	 *
	 * @return {Promise<void>}
	 */
	const disconnectBluesky = useCallback(async () => {
		setSaving(true);
		setError(null);
		try {
			const updated = await saveSettings({
				platforms: { bluesky: { app_password: null, handle: '' } },
			});
			applyLoadedSettings(updated);
			setPendingBlueskyPassword('');
			setTestResult((prev) => {
				const { bluesky, ...rest } = prev;
				void bluesky;
				return rest;
			});
			setSavedAt(Date.now());
		} catch (e) {
			setError(e.message);
		}
		setSaving(false);
	}, [applyLoadedSettings]);

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
						<tr>
							<th>{__('Default platforms', 'apex-cast')}</th>
							<td>
								<fieldset>
									<legend className="screen-reader-text">
										{__('Default platforms', 'apex-cast')}
									</legend>
									{PLATFORMS.map((p) => {
										const selected =
											settings.store?.default_platforms ||
											[];
										const checked = selected.includes(p.id);
										const toggle = () => {
											const next = checked
												? selected.filter(
														(x) => x !== p.id
													)
												: [...selected, p.id];
											update(
												'store.default_platforms',
												next
											);
										};
										const inputId = `apex-cast-default-platform-${p.id}`;
										return (
											<label
												key={p.id}
												htmlFor={inputId}
												className="apex-cast-default-platform"
											>
												<input
													id={inputId}
													type="checkbox"
													checked={checked}
													onChange={toggle}
												/>{' '}
												{p.label}
											</label>
										);
									})}
								</fieldset>
								<p className="description">
									{__(
										'Which platforms are pre-checked in the Apex Cast metabox on the product editor. Unconfigured platforms are still hidden in the metabox until you connect them.',
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

			{tab === 'platforms' && (
				<>
					<p className="description">
						{__(
							'Each platform has its own connection flow. Pinterest connects with a personal account; Facebook + Instagram require a Page + Creator account linked through Meta.',
							'apex-cast'
						)}
					</p>
					<table className="form-table">
						<tbody>
							{PLATFORMS.map((p) => {
								let body;
								if (p.id === 'pinterest') {
									body = renderPinterestRow({
										settings,
										update,
										runTest,
										disconnectPinterest,
										renderTestResult,
										onStartConnect: startPinterestConnect,
										pinterestBoards,
										boardsLoading: pinterestBoardsLoading,
										boardsError: pinterestBoardsError,
										savedApiMode: savedPinterestApiMode,
									});
								} else if (p.id === 'facebook') {
									body = renderFacebookRow({
										settings,
										runTest,
										disconnectFacebook,
										renderTestResult,
										onStartConnect: startFacebookConnect,
									});
								} else if (p.id === 'instagram') {
									body = renderInstagramRow({
										settings,
										runTest,
										renderTestResult,
									});
								} else if (p.id === 'bluesky') {
									body = renderBlueskyRow({
										settings,
										update,
										runTest,
										disconnectBluesky,
										renderTestResult,
										pendingPassword: pendingBlueskyPassword,
										onPendingPassword:
											setPendingBlueskyPassword,
									});
								} else {
									body = renderPlatformPlaceholder();
								}
								return (
									<tr key={p.id}>
										<th>{p.label}</th>
										<td>{body}</td>
									</tr>
								);
							})}
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
