/**
 * Apex Cast metabox entry — mounts the React app into the product editor sidebar.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import ApexCastMetabox from './metabox/ApexCastMetabox';
import './metabox/styles.scss';

const container = document.getElementById('apex-cast-metabox-root');

if (container) {
	const bootstrapData =
		typeof window !== 'undefined' && window.APEX_CAST_DATA
			? window.APEX_CAST_DATA
			: {};
	createRoot(container).render(
		<ApexCastMetabox bootstrapData={bootstrapData} />
	);
}
