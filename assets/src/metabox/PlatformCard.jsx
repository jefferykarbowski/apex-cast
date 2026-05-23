/**
 * Editable per-platform draft card with a character-count indicator.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

/**
 * Per-platform character limits mirrored from SPEC §5.2. Display-only; the
 * server does not enforce these but the UI helps the user stay within them.
 */
const PLATFORM_LIMITS = {
	facebook: 500,
	instagram: 2200,
	pinterest: 500,
	threads: 500,
	bluesky: 300,
	x: 280,
	tiktok: 200,
	reddit: 300,
};

/**
 * Editable card showing a single platform's draft.
 *
 * @param {Object}   props
 * @param {string}   props.platform Platform identifier.
 * @param {Object}   props.draft    Draft object with content + hashtags.
 * @param {Function} props.onChange Receives the new content string.
 * @param {boolean}  props.disabled When true, the textarea is read-only.
 * @return {Element} React element.
 */
export default function PlatformCard({ platform, draft, onChange, disabled }) {
	const content = (draft && draft.content) || '';
	const hashtags = (draft && draft.hashtags) || [];
	const limit = PLATFORM_LIMITS[platform] || 1000;
	const count = content.length;
	const over = count > limit;

	const cardClass = `apex-cast-platform-card${over ? ' over-limit' : ''}`;

	return (
		<div className={cardClass}>
			<div className="apex-cast-platform-card-header">
				<strong>{platform}</strong>
				<span className={`apex-cast-char-count${over ? ' over' : ''}`}>
					{count} / {limit}
				</span>
			</div>
			<textarea
				value={content}
				onChange={(event) => onChange(event.target.value)}
				disabled={disabled}
				rows={4}
				aria-label={__('Draft content', 'apex-cast')}
			/>
			{hashtags.length > 0 && (
				<div className="apex-cast-hashtags">
					{hashtags.map((tag) => (
						<span key={tag} className="apex-cast-hashtag">
							{tag}
						</span>
					))}
				</div>
			)}
		</div>
	);
}
