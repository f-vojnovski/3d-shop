import { vi } from 'vitest';

vi.mock('../cookies/cookiesConsentChecker', () => ({
  default: () => false,
}));

const { default: reducer, postLoginData } = await import('./authSlice');

describe('auth reducer', () => {
  it('starts logged out and idle', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toMatchObject({
      token: null,
      user: null,
      status: 'idle',
    });
  });

  // Regression: a bare return made this dispatch `fulfilled` with an undefined
  // payload, the reducer threw reading payload.user, and status stayed
  // 'loading' forever, leaving the login page spinning.
  it('rejects login instead of hanging when cookie consent is missing', async () => {
    const dispatched = [];
    const thunk = postLoginData({ name: 'a', password: 'b' });

    await thunk(
      (action) => dispatched.push(action),
      () => ({ auth: { token: null } }),
      undefined
    );

    const types = dispatched.map((a) => a.type);
    expect(types).toContain('auth/postLoginData/rejected');
    expect(types).not.toContain('auth/postLoginData/fulfilled');

    const final = dispatched.reduce((state, action) => reducer(state, action), undefined);
    expect(final.status).toBe('failed');
    expect(final.error).toMatch(/cookies/i);
  });
});
