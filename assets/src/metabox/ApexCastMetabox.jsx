/**
 * Main metabox React component — orchestrates state, REST calls, and child components.
 *
 * @package
 */

import { useReducer, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import PlatformPicker from './PlatformPicker';
import PlatformCard from './PlatformCard';
import SendBar from './SendBar';
import { reducer, INITIAL_STATE } from './reducer';
import { generateDrafts, sendDrafts } from './api';

/**
 * Build the initial reducer state from the PHP-supplied bootstrap data.
 *
 * @param {Object} bootstrap The `window.APEX_CAST_DATA` shape.
 * @return {Object} Initial state for `useReducer`.
 */
function buildInitialState(bootstrap) {
	const initialDrafts =
		bootstrap.initialDrafts && typeof bootstrap.initialDrafts === 'object'
			? bootstrap.initialDrafts
			: {};
	const defaults =
		Array.isArray(bootstrap.defaultPlatforms) &&
		bootstrap.defaultPlatforms.length > 0
			? bootstrap.defaultPlatforms
			: ['facebook'];

	return {
		...INITIAL_STATE,
		selectedPlatforms: defaults,
		drafts: initialDrafts,
		status: Object.keys(initialDrafts).length > 0 ? 'drafted' : 'empty',
	};
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
	const supportedPlatforms = bootstrapData.supportedPlatforms || [];

	const [state, dispatch] = useReducer(
		reducer,
		bootstrapData,
		buildInitialState
	);

	const isGenerating = state.status === 'generating';
	const isSending = state.status === 'sending';

	const handleGenerate = useCallback(async () => {
		if (!productId || state.selectedPlatforms.length === 0) {
			return;
		}
		dispatch({ type: 'GENERATE_START' });
		try {
			const result = await generateDrafts(
				productId,
				state.selectedPlatforms
			);
			dispatch({
				type: 'GENERATE_SUCCESS',
				drafts: result.drafts || {},
				notes: result.notes || '',
			});
		} catch (error) {
			dispatch({
				type: 'GENERATE_FAILURE',
				error: error.message,
			});
		}
	}, [productId, state.selectedPlatforms]);

	const handleSend = useCallback(async () => {
		if (!productId) {
			return;
		}
		const platformsWithDrafts = state.selectedPlatforms.filter((platform) =>
			Boolean(state.drafts[platform])
		);
		if (platformsWithDrafts.length === 0) {
			return;
		}
		dispatch({ type: 'SEND_START' });
		try {
			const result = await sendDrafts(
				productId,
				state.drafts,
				platformsWithDrafts,
				state.postType
			);
			dispatch({ type: 'SEND_SUCCESS', jobId: result.job_id });
		} catch (error) {
			dispatch({ type: 'SEND_FAILURE', error: error.message });
		}
	}, [productId, state.selectedPlatforms, state.drafts, state.postType]);

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

	const platformsWithDrafts = state.selectedPlatforms.filter((platform) =>
		Boolean(state.drafts[platform])
	);

	return (
		<div className="apex-cast-metabox">
			<PlatformPicker
				supported={supportedPlatforms}
				selected={state.selectedPlatforms}
				onChange={(platforms) =>
					dispatch({ type: 'SET_PLATFORMS', platforms })
				}
				disabled={isGenerating || isSending}
			/>

			<button
				type="button"
				className="button button-primary apex-cast-generate"
				onClick={handleGenerate}
				disabled={isGenerating || state.selectedPlatforms.length === 0}
			>
				{isGenerating
					? __('Generating…', 'apex-cast')
					: __('Generate social drafts', 'apex-cast')}
			</button>

			{state.error && (
				<div className="notice notice-error inline apex-cast-error">
					<p>{state.error}</p>
				</div>
			)}

			{state.notes && (
				<p className="apex-cast-notes">
					<em>{__('Creative angle:', 'apex-cast')}</em> {state.notes}
				</p>
			)}

			{platformsWithDrafts.map((platform) => (
				<PlatformCard
					key={platform}
					platform={platform}
					draft={state.drafts[platform]}
					onChange={(content) =>
						dispatch({
							type: 'EDIT_DRAFT',
							platform,
							content,
						})
					}
					disabled={isSending}
				/>
			))}

			{platformsWithDrafts.length > 0 && state.status !== 'sent' && (
				<SendBar
					postType={state.postType}
					onPostTypeChange={(postType) =>
						dispatch({ type: 'SET_POST_TYPE', postType })
					}
					onSend={handleSend}
					sending={isSending}
					platformCount={platformsWithDrafts.length}
				/>
			)}

			{state.status === 'sent' && (
				<p className="apex-cast-sent">
					{__('Sent to scheduler.', 'apex-cast')}
				</p>
			)}
		</div>
	);
}
