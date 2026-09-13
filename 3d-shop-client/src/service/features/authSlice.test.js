import { vi } from 'vitest';

vi.mock('../cookies/cookiesConsentChecker', () => ({
  default: () => false,
}));

const { default: reducer, postLoginData, logoutUser } = await import('./authSlice');

describe('auth reducer', () => {
  it('starts logged out and idle', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toEqual({
      user: null,
      status: 'idle',
      error: null,
    });
  });

  // A bare return here dispatched `fulfilled` with no payload and hung the page.
  it('rejects login instead of hanging when cookie consent is missing', async () => {
    const dispatched = [];
    const thunk = postLoginData({ name: 'a', password: 'b' });

    await thunk(
      (action) => dispatched.push(action),
      () => ({ auth: { user: null } }),
      undefined
    );

    const types = dispatched.map((a) => a.type);
    expect(types).toContain('auth/postLoginData/rejected');
    expect(types).not.toContain('auth/postLoginData/fulfilled');

    const final = dispatched.reduce((state, action) => reducer(state, action), undefined);
    expect(final.status).toBe('failed');
    expect(final.error).toMatch(/cookies/i);
  });

  it('does not keep an error from a failed logout', () => {
    const loggedIn = { user: { id: 1 }, status: 'succeeded', error: null };

    const state = reducer(loggedIn, {
      type: logoutUser.rejected.type,
      error: { message: 'Network Error' },
    });

    expect(state.user).toBeNull();
    expect(state.error).toBeNull();
    expect(state.status).toBe('idle');
  });
});
