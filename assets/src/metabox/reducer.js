/**
 * Apex Cast metabox state machine.
 *
 * Modeled per SPEC §8.2:
 *   empty -> generating -> drafted -> sending -> sent
 *                                  \-> error
 *
 * Kept as a plain reducer (no Redux) so it's trivially unit-testable.
 *
 * @package
 */

/**
 * Initial state used by `useReducer` when the metabox first mounts.
 */
export const INITIAL_STATE = {
	status: 'empty',
	selectedPlatforms: [],
	drafts: {},
	notes: '',
	postType: 'draft',
	error: null,
	jobId: null,
};

/**
 * Pure reducer for the metabox state machine.
 *
 * @param {Object} state  Previous state.
 * @param {Object} action Action object with a `type` field.
 * @return {Object} Next state.
 */
export function reducer(state, action) {
	switch (action.type) {
		case 'SET_PLATFORMS':
			return { ...state, selectedPlatforms: action.platforms };

		case 'SET_POST_TYPE':
			return { ...state, postType: action.postType };

		case 'GENERATE_START':
			return { ...state, status: 'generating', error: null };

		case 'GENERATE_SUCCESS':
			return {
				...state,
				status: 'drafted',
				drafts: action.drafts,
				notes: action.notes,
			};

		case 'GENERATE_FAILURE':
			return { ...state, status: 'error', error: action.error };

		case 'EDIT_DRAFT':
			return {
				...state,
				drafts: {
					...state.drafts,
					[action.platform]: {
						...(state.drafts[action.platform] || {}),
						content: action.content,
					},
				},
			};

		case 'SEND_START':
			return { ...state, status: 'sending', error: null };

		case 'SEND_SUCCESS':
			return { ...state, status: 'sent', jobId: action.jobId };

		case 'SEND_FAILURE':
			return { ...state, status: 'error', error: action.error };

		default:
			return state;
	}
}
