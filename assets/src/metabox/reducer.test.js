/**
 * Reducer tests — pin every transition in the metabox state machine.
 *
 * @package
 */

import { reducer, INITIAL_STATE } from './reducer';

describe('metabox reducer', () => {
	it('starts empty with no drafts and no error', () => {
		expect(INITIAL_STATE.status).toBe('empty');
		expect(INITIAL_STATE.drafts).toEqual({});
		expect(INITIAL_STATE.error).toBeNull();
	});

	it('SET_PLATFORMS replaces the selected platforms', () => {
		const next = reducer(INITIAL_STATE, {
			type: 'SET_PLATFORMS',
			platforms: ['facebook', 'x'],
		});
		expect(next.selectedPlatforms).toEqual(['facebook', 'x']);
	});

	it('GENERATE_START moves to generating and clears any prior error', () => {
		const next = reducer(
			{ ...INITIAL_STATE, status: 'error', error: 'old' },
			{ type: 'GENERATE_START' }
		);
		expect(next.status).toBe('generating');
		expect(next.error).toBeNull();
	});

	it('GENERATE_SUCCESS stores drafts + notes and moves to drafted', () => {
		const next = reducer(
			{ ...INITIAL_STATE, status: 'generating' },
			{
				type: 'GENERATE_SUCCESS',
				drafts: { facebook: { content: 'Hi.' } },
				notes: 'A casual angle.',
			}
		);
		expect(next.status).toBe('drafted');
		expect(next.drafts.facebook.content).toBe('Hi.');
		expect(next.notes).toBe('A casual angle.');
	});

	it('GENERATE_FAILURE moves to error with the message', () => {
		const next = reducer(INITIAL_STATE, {
			type: 'GENERATE_FAILURE',
			error: 'nope',
		});
		expect(next.status).toBe('error');
		expect(next.error).toBe('nope');
	});

	it('EDIT_DRAFT updates content for a single platform without touching others', () => {
		const start = {
			...INITIAL_STATE,
			status: 'drafted',
			drafts: {
				facebook: { content: 'old fb' },
				x: { content: 'old x' },
			},
		};
		const next = reducer(start, {
			type: 'EDIT_DRAFT',
			platform: 'facebook',
			content: 'new fb',
		});
		expect(next.drafts.facebook.content).toBe('new fb');
		expect(next.drafts.x.content).toBe('old x');
	});

	it('EDIT_DRAFT initialises a draft for a platform that has none yet', () => {
		const next = reducer(INITIAL_STATE, {
			type: 'EDIT_DRAFT',
			platform: 'threads',
			content: 'new',
		});
		expect(next.drafts.threads.content).toBe('new');
	});

	it('SEND_START moves to sending and clears any prior error', () => {
		const next = reducer(
			{ ...INITIAL_STATE, status: 'error', error: 'old' },
			{ type: 'SEND_START' }
		);
		expect(next.status).toBe('sending');
		expect(next.error).toBeNull();
	});

	it('SEND_SUCCESS records the job id', () => {
		const next = reducer(
			{ ...INITIAL_STATE, status: 'sending' },
			{ type: 'SEND_SUCCESS', jobId: 42 }
		);
		expect(next.status).toBe('sent');
		expect(next.jobId).toBe(42);
	});

	it('SEND_FAILURE stores the error', () => {
		const next = reducer(
			{ ...INITIAL_STATE, status: 'sending' },
			{ type: 'SEND_FAILURE', error: 'backend down' }
		);
		expect(next.status).toBe('error');
		expect(next.error).toBe('backend down');
	});

	it('unknown action returns the state object unchanged (identity)', () => {
		const next = reducer(INITIAL_STATE, { type: 'UNKNOWN' });
		expect(next).toBe(INITIAL_STATE);
	});
});
