import reducer, { customViewDrawn, fetchCustomViews } from './customViewSlice';

const opened = (state, productId) =>
  reducer(state, { type: fetchCustomViews.pending.type, meta: { arg: productId } });

const landed = (state, view) => reducer(state, customViewDrawn(view));

const view = (id, productId) => ({
  id,
  productId,
  pass: 'shaded',
  status: 'ready',
  url: `/views/${id}.png`,
});

describe('custom views belong to a product', () => {
  it('empties the list when a different product is opened', () => {
    let state = opened(undefined, 7);
    state = landed(state, view(1, 7));

    expect(state.views).toHaveLength(1);

    state = opened(state, 9);

    expect(state.views).toHaveLength(0);
    expect(state.productId).toBe(9);
  });

  /** Asking again for the same product must not blank what is on screen. */
  it('keeps the list when the same product is refetched', () => {
    let state = landed(opened(undefined, 7), view(1, 7));

    state = opened(state, 7);

    expect(state.views).toHaveLength(1);
  });

  /** Otherwise it lands in the new product's panel, offering to publish it there. */
  it('ignores a render that finished for the product you left', () => {
    let state = opened(undefined, 7);

    state = opened(state, 9);
    state = landed(state, view(1, 7));

    expect(state.views).toHaveLength(0);
  });

  it('accepts a render for the product on screen', () => {
    const state = landed(opened(undefined, 9), view(1, 9));

    expect(state.views).toHaveLength(1);
  });

  /** Older events carry no product; dropping them would lose real renders. */
  it('still accepts an event that does not say which product it is for', () => {
    const state = landed(opened(undefined, 9), { ...view(1, 9), productId: undefined });

    expect(state.views).toHaveLength(1);
  });

  it('replaces a view rather than listing it twice', () => {
    let state = landed(opened(undefined, 9), { ...view(1, 9), status: 'queued' });

    state = landed(state, view(1, 9));

    expect(state.views).toHaveLength(1);
    expect(state.views[0].status).toBe('ready');
  });
});
