/**
 * Apex Cast settings page entry — mounts the React app under Settings → Apex Cast.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import SettingsApp from './settings/SettingsApp';
import './settings/styles.scss';

const container = document.getElementById('apex-cast-settings-root');

if (container) {
	const bootstrapData =
		typeof window !== 'undefined' && window.APEX_CAST_SETTINGS_DATA
			? window.APEX_CAST_SETTINGS_DATA
			: {};
	createRoot(container).render(<SettingsApp bootstrapData={bootstrapData} />);
}
