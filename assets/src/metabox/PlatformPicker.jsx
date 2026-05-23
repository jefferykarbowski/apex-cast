/**
 * Per-platform checkbox grid that drives the SET_PLATFORMS action.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

/**
 * Two-column checkbox grid of supported platforms.
 *
 * @param {Object}   props
 * @param {string[]} props.supported Platform identifiers the backend can post to.
 * @param {string[]} props.selected  Currently selected platforms.
 * @param {Function} props.onChange  Receives the next selected array.
 * @param {boolean}  props.disabled  When true, all inputs are disabled.
 * @return {Element} React element.
 */
export default function PlatformPicker({
	supported,
	selected,
	onChange,
	disabled,
}) {
	const toggle = (platform) => {
		if (selected.includes(platform)) {
			onChange(selected.filter((p) => p !== platform));
		} else {
			onChange([...selected, platform]);
		}
	};

	return (
		<fieldset className="apex-cast-platforms">
			<legend className="screen-reader-text">
				{__('Select platforms', 'apex-cast')}
			</legend>
			{supported.map((platform) => {
				const inputId = `apex-cast-platform-${platform}`;
				return (
					<label
						key={platform}
						htmlFor={inputId}
						className="apex-cast-platform-option"
					>
						<input
							id={inputId}
							type="checkbox"
							checked={selected.includes(platform)}
							onChange={() => toggle(platform)}
							disabled={disabled}
						/>
						{platform}
					</label>
				);
			})}
		</fieldset>
	);
}
