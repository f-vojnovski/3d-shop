import reducer, {
  fetchProducts,
  fetchUploadedProductsForCurrentUser,
} from './productsSlice';

const page = (names) => ({
  data: names.map((name, id) => ({ id, name })),
  current_page: 1,
  meta: { per_page: 12, total: names.length, last_page: 1 },
});

const asked = (state, thunk, requestId) =>
  reducer(state, { type: thunk.pending.type, meta: { requestId } });

const answered = (state, thunk, requestId, payload) =>
  reducer(state, { type: thunk.fulfilled.type, meta: { requestId }, payload });

describe('one list, three sources', () => {
  /** Leaving your uploads for the catalogue mid-request showed drafts publicly. */
  it('ignores an answer to a question already moved on from', () => {
    let state = asked(undefined, fetchUploadedProductsForCurrentUser, 'mine');
    state = asked(state, fetchProducts, 'catalogue');

    state = answered(state, fetchUploadedProductsForCurrentUser, 'mine', page(['my draft']));

    expect(state.products).toHaveLength(0);

    state = answered(state, fetchProducts, 'catalogue', page(['for sale']));

    expect(state.products.map((one) => one.name)).toEqual(['for sale']);
  });

  it('takes the answer to the question it is still waiting on', () => {
    let state = asked(undefined, fetchProducts, 'one');

    state = answered(state, fetchProducts, 'one', page(['a', 'b']));

    expect(state.products).toHaveLength(2);
    expect(state.status).toBe('succeeded');
  });

  /** A slow first answer must not overwrite a fast second one. */
  it('keeps the newer list when an older answer arrives late', () => {
    let state = asked(undefined, fetchProducts, 'first');
    state = asked(state, fetchProducts, 'second');

    state = answered(state, fetchProducts, 'second', page(['new']));
    state = answered(state, fetchProducts, 'first', page(['old']));

    expect(state.products.map((one) => one.name)).toEqual(['new']);
  });
});
