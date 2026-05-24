/**
 * Apex Cast metabox — one-click broadcast.
 *
 * Shows a preview of what will be sent (product's short description + featured
 * image + hashtags derived from product tags), lets the user pick platforms,
 * and broadcasts. No AI generation, no draft editor — server reads the
 * canonical product fields on send.
 */

import { useState, useCallback } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

import PlatformPicker from './PlatformPicker';
import { sendProduct } from './api';

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

	const hashtags = productTags.map(tagToHashtag).filter((h) => h !== '');

	const handleSend = useCallback(async () => {
		if (!productId || selectedPlatforms.length === 0) {
			return;
		}
		setStatus('sending');
		setError(null);
		setResults({});
		try {
			const result = await sendProduct(productId, selectedPlatforms);
			setResults(result.platform_results || {});
			setStatus('done');
		} catch (e) {
			setError(e.message);
			setStatus('error');
		}
	}, [productId, selectedPlatforms]);

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

	return (
		<div className="apex-cast-metabox">
			<PlatformPicker
				supported={supportedPlatforms}
				selected={selectedPlatforms}
				onChange={setSelectedPlatforms}
				disabled={sending}
			/>

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
