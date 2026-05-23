/**
 * Send action bar — post-type radio + submit button.
 *
 * @package
 */

import { __, sprintf, _n } from '@wordpress/i18n';

const POST_TYPES = ['now', 'schedule', 'draft'];

/**
 * Bottom action area of the metabox.
 *
 * @param {Object}   props
 * @param {string}   props.postType         Currently selected post type.
 * @param {Function} props.onPostTypeChange Receives the next post-type string.
 * @param {Function} props.onSend           Click handler for the send button.
 * @param {boolean}  props.sending          When true, send button shows a loading label.
 * @param {number}   props.platformCount    Number of platforms about to be sent to.
 * @return {Element} React element.
 */
export default function SendBar({
	postType,
	onPostTypeChange,
	onSend,
	sending,
	platformCount,
}) {
	const sendLabel = sending
		? __('Sending…', 'apex-cast')
		: sprintf(
				/* translators: %d: number of platforms. */
				_n(
					'Send to scheduler (%d platform)',
					'Send to scheduler (%d platforms)',
					platformCount,
					'apex-cast'
				),
				platformCount
			);

	return (
		<div className="apex-cast-send-bar">
			<fieldset className="apex-cast-post-type">
				<legend className="screen-reader-text">
					{__('Post type', 'apex-cast')}
				</legend>
				{POST_TYPES.map((type) => {
					const inputId = `apex-cast-post-type-${type}`;
					return (
						<label key={type} htmlFor={inputId}>
							<input
								id={inputId}
								type="radio"
								name="apex-cast-post-type"
								value={type}
								checked={postType === type}
								onChange={() => onPostTypeChange(type)}
								disabled={sending}
							/>
							{type}
						</label>
					);
				})}
			</fieldset>
			<button
				type="button"
				className="button button-primary apex-cast-send-button"
				onClick={onSend}
				disabled={sending || platformCount === 0}
			>
				{sendLabel}
			</button>
		</div>
	);
}
