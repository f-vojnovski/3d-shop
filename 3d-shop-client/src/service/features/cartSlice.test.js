import reducer, {
  addToCart,
  clearCart,
  clearCheckoutError,
  removeFromCart,
} from './cartSlice';

const product = (id, priceCents) => ({ id, price_cents: priceCents, name: `Product ${id}` });

describe('cart reducer', () => {
  it('starts empty', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toMatchObject({
      products: [],
      total: 0,
    });
  });

  it('adds a product and accumulates the total', () => {
    const state = reducer(undefined, addToCart(product(1, 4000)));

    expect(state.products).toHaveLength(1);
    expect(state.total).toBe(4000);
  });

  it('sums the prices of several products', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = reducer(state, addToCart(product(2, 300)));

    expect(state.products.map((p) => p.id)).toEqual([1, 2]);
    expect(state.total).toBe(4300);
  });

  // The reason prices are stored as minor units: as floats these two sum to
  // 30.299999999999997, which would reach the payment step as the cart total.
  it('sums exactly, with no floating point drift', () => {
    let state = reducer(undefined, addToCart(product(1, 1010)));
    state = reducer(state, addToCart(product(2, 2020)));

    expect(state.total).toBe(3030);
    expect(0.1 * 101 + 0.1 * 202).not.toBe(30.3);
  });

  it('ignores a product that is already in the cart', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = reducer(state, addToCart(product(1, 4000)));

    expect(state.products).toHaveLength(1);
    expect(state.total).toBe(4000);
  });

  it('empties the cart on clearCart', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = reducer(state, clearCart());

    expect(state).toMatchObject({ products: [], total: 0 });
  });

  it('removes a product and takes its price back off the total', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = reducer(state, addToCart(product(2, 300)));

    state = reducer(state, removeFromCart(1));

    expect(state.products.map((p) => p.id)).toEqual([2]);
    expect(state.total).toBe(300);
  });

  it('ignores a removal for something not in the cart', () => {
    const state = reducer(undefined, addToCart(product(1, 4000)));

    expect(reducer(state, removeFromCart(99))).toEqual(state);
  });

  it('empties the total when the last product is removed', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = reducer(state, removeFromCart(1));

    expect(state.products).toEqual([]);
    expect(state.total).toBe(0);
  });

  // A failure used to sit in the slice forever, so a later visit to checkout
  // reported a problem that had already been dealt with.
  it('forgets a failed checkout without emptying the cart', () => {
    let state = reducer(undefined, addToCart(product(1, 4000)));
    state = { ...state, status: 'failed', error: 'Payment declined.' };

    state = reducer(state, clearCheckoutError());

    expect(state.status).toBe('idle');
    expect(state.error).toBeNull();
    expect(state.products).toHaveLength(1);
    expect(state.total).toBe(4000);
  });
});
