/**
 * Apex Cast metabox — one-click broadcast.
 *
 * Shows a preview of what will be sent (product's short description + featured
 * image + hashtags derived from product tags), lets the user pick platforms,
 * and broadcasts. No AI generation, no draft editor — server reads the
 * canonical product fields on send.
 */

import { useState, useCallback, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import PlatformPicker from './PlatformPicker';
import { sendProduct, listPinterestBoards } from './api';

/**
 * Format a WooCommerce product tag as a hashtag.
 *
 * @param {string} tag Raw tag string.
 * @return {string} Lowercased, alphanumeric-only hashtag (e.g. "#viciousfun").
 */
function tagToHashtag(tag) {
	const stripped = String(tag).replace(/[^a-zA-Z0-9]/g, '');
	return stripped ? `#${stripped.toLowerCase()}` : '';
}

/**
 * Pretty label for a destination platform in the preview list.
 *
 * @param {string} platform Platform id.
 * @return {string} Human-readable label.
 */
function platformLabel(platform) {
	switch (platform) {
		case 'pinterest':
			return 'Pinterest';
		case 'facebook':
			return 'Facebook';
		case 'instagram':
			return 'Instagram';
		default:
			return platform;
	}
}

/**
 * Main metabox component.
 *
 * @param {Object} props
 * @param {Object} props.bootstrapData Bootstrap object localised by PHP.
 * @return {Element} React element.
 */
export default function ApexCastMetabox({ bootstrapData }) {
	const productId = bootstrapData.productId || 0;
	const shortDescription = bootstrapData.shortDescription || '';
	const featuredImage = bootstrapData.featuredImage || '';
	const productTags = Array.isArray(bootstrapData.tags)
		? bootstrapData.tags
		: [];
	const supportedPlatforms = Array.isArray(bootstrapData.supportedPlatforms)
		? bootstrapData.supportedPlatforms
		: [];
	const configuredPlatforms = Array.isArray(bootstrapData.configuredPlatforms)
		? bootstrapData.configuredPlatforms
		: [];
	const pinterestResolvedBoardName =
		typeof bootstrapData.pinterestResolvedBoardName === 'string'
			? bootstrapData.pinterestResolvedBoardName
			: '';

	// Default to whichever platforms are *both* enabled in settings and
	// actually configured (connected). If none are configured, fall back to
	// the user's default selections so the picker still shows.
	const defaultSelected = (
		Array.isArray(bootstrapData.defaultPlatforms)
			? bootstrapData.defaultPlatforms
			: []
	).filter((p) => configuredPlatforms.includes(p));

	const [selectedPlatforms, setSelectedPlatforms] = useState(
		defaultSelected.length > 0
			? defaultSelected
			: configuredPlatforms.slice(0, 3)
	);
	const [status, setStatus] = useState('idle');
	const [error, setError] = useState(null);
	const [results, setResults] = useState({});
	const [pinterestBoardOverride, setPinterestBoardOverride] = useState('');
	const [pinterestBoards, setPinterestBoards] = useState(null);
	const [pinterestBoardsLoading, setPinterestBoardsLoading] = useState(false);
	const [pinterestBoardsError, setPinterestBoardsError] = useState(null);

	const hashtags = productTags.map(tagToHashtag).filter((h) => h !== '');

	const pinterestSelected = selectedPlatforms.includes('pinterest');

	// Lazily fetch the board list the first time Pinterest is selected. Cached
	// thereafter in component state — toggling pinterest off/on doesn't refetch.
	useEffect(() => {
		if (!pinterestSelected) {
			return;
		}
		if (pinterestBoards !== null || pinterestBoardsLoading) {
			return;
		}
		setPinterestBoardsLoading(true);
		setPinterestBoardsError(null);
		listPinterestBoards()
			.then((data) => {
				const boards = Array.isArray(data?.boards) ? data.boards : [];
				const sorted = [...boards].sort((a, b) =>
					String(a.name || '').localeCompare(String(b.name || ''))
				);
				setPinterestBoards(sorted);
			})
			.catch((e) => {
				setPinterestBoardsError(e.message);
				setPinterestBoards([]);
			})
			.finally(() => setPinterestBoardsLoading(false));
	}, [pinterestSelected, pinterestBoards, pinterestBoardsLoading]);

	const autoLabel = useMemo(() => {
		const target =
			pinterestResolvedBoardName || __('Default board', 'apex-cast');
		return sprintf(
			/* translators: %s: resolved board name */
			__('Auto: %s', 'apex-cast'),
			target
		);
	}, [pinterestResolvedBoardName]);

	const handleSend = useCallback(async () => {
		if (!productId || selectedPlatforms.length === 0) {
			return;
		}
		setStatus('sending');
		setError(null);
		setResults({});

		const platformOptions = {};
		if (pinterestSelected && pinterestBoardOverride) {
			platformOptions.pinterest = {
				board_id_override: pinterestBoardOverride,
			};
		}

		try {
			const result = await sendProduct(
				productId,
				selectedPlatforms,
				Object.keys(platformOptions).length > 0
					? platformOptions
					: undefined
			);
			setResults(result.platform_results || {});
			setStatus('done');
		} catch (e) {
			setError(e.message);
			setStatus('error');
		}
	}, [
		productId,
		selectedPlatforms,
		pinterestSelected,
		pinterestBoardOverride,
	]);

	if (!productId) {
		return (
			<p className="apex-cast-empty-product">
				{__(
					'Save this product first to enable Apex Cast.',
					'apex-cast'
				)}
			</p>
		);
	}

	if (!shortDescription) {
		return (
			<p className="apex-cast-empty-product">
				{__(
					'Add a short description to this product (or it will use the product title) before broadcasting.',
					'apex-cast'
				)}
			</p>
		);
	}

	if (configuredPlatforms.length === 0) {
		return (
			<p className="apex-cast-empty-product">
				{__(
					'Connect at least one platform under Settings → Apex Cast → Platforms to start broadcasting.',
					'apex-cast'
				)}
			</p>
		);
	}

	const sending = status === 'sending';
	const platformCount = selectedPlatforms.length;
	const sendLabel = sending
		? __('Sending…', 'apex-cast')
		: sprintf(
				/* translators: %d: number of platforms. */
				_n(
					'Send to %d platform',
					'Send to %d platforms',
					platformCount,
					'apex-cast'
				),
				platformCount
			);

	// What the client *expects* Pinterest to resolve to, accounting for the
	// override. The server is still the source of truth at publish time.
	const pinterestDestinationLabel = (() => {
		if (pinterestBoardOverride && Array.isArray(pinterestBoards)) {
			const match = pinterestBoards.find(
				(b) => String(b.id) === pinterestBoardOverride
			);
			if (match) {
				return match.name;
			}
		}
		return pinterestResolvedBoardName || __('Default board', 'apex-cast');
	})();

	return (
		<div className="apex-cast-metabox">
			<PlatformPicker
				supported={supportedPlatforms}
				selected={selectedPlatforms}
				onChange={setSelectedPlatforms}
				disabled={sending}
			/>

			{pinterestSelected && (
				<div className="apex-cast-pinterest-override">
					<label
						htmlFor="apex-cast-pinterest-board-override"
						className="apex-cast-pinterest-override-label"
					>
						{__('Pin to', 'apex-cast')}
					</label>
					<select
						id="apex-cast-pinterest-board-override"
						value={pinterestBoardOverride}
						onChange={(e) =>
							setPinterestBoardOverride(e.target.value)
						}
						disabled={sending}
					>
						<option value="">{autoLabel}</option>
						{Array.isArray(pinterestBoards) &&
							pinterestBoards.map((board) => (
								<option key={board.id} value={board.id}>
									{board.name}
								</option>
							))}
					</select>
					{pinterestBoardsLoading && (
						<span className="apex-cast-pinterest-override-loading">
							{__('Loading boards…', 'apex-cast')}
						</span>
					)}
					{pinterestBoardsError && (
						<span className="apex-cast-pinterest-override-error">
							{pinterestBoardsError}
						</span>
					)}
				</div>
			)}

			<div className="apex-cast-preview">
				{featuredImage && (
					<img
						className="apex-cast-preview-image"
						src={featuredImage}
						alt=""
					/>
				)}
				<p className="apex-cast-preview-caption">{shortDescription}</p>
				{hashtags.length > 0 && (
					<p className="apex-cast-preview-hashtags">
						{hashtags.join(' ')}
					</p>
				)}
				{selectedPlatforms.length > 0 && (
					<ul className="apex-cast-preview-destinations">
						{selectedPlatforms.map((platform) => {
							const label = platformLabel(platform);
							if (platform === 'pinterest') {
								return (
									<li key={platform}>
										{label}
										{' → '}
										{pinterestDestinationLabel}
									</li>
								);
							}
							return <li key={platform}>{label}</li>;
						})}
					</ul>
				)}
			</div>

			<button
				type="button"
				className="button button-primary apex-cast-send-button"
				onClick={handleSend}
				disabled={sending || platformCount === 0}
			>
				{sendLabel}
			</button>

			{status === 'error' && error && (
				<div className="notice notice-error inline apex-cast-error">
					<p>{error}</p>
				</div>
			)}

			{status === 'done' && Object.keys(results).length > 0 && (
				<ul className="apex-cast-results">
					{Object.entries(results).map(([platform, result]) => (
						<li
							key={platform}
							className={
								result.success
									? 'apex-cast-result success'
									: 'apex-cast-result failure'
							}
						>
							<strong>
								{result.success ? '✓' : '✗'} {platform}
							</strong>
							{result.success && result.platform_url && (
								<>
									{' — '}
									<a
										href={result.platform_url}
										target="_blank"
										rel="noopener noreferrer"
									>
										{__('view post', 'apex-cast')}
									</a>
								</>
							)}
							{!result.success && result.error_message && (
								<span className="apex-cast-result-error">
									{' — '}
									{result.error_message}
								</span>
							)}
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
