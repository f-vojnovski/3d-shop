import reducer, { addToCart, clearCart } from './cartSlice';

const product = (id, price) => ({ id, price, name: `Product ${id}` });

describe('cart reducer', () => {
  it('starts empty', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toMatchObject({
      products: [],
      total: 0,
    });
  });

  it('adds a product and accumulates the total', () => {
    const state = reducer(undefined, addToCart(product(1, '40')));

    expect(state.products).toHaveLength(1);
    expect(state.total).toBe(40);
  });

  it('sums the prices of several products', () => {
    let state = reducer(undefined, addToCart(product(1, '40')));
    state = reducer(state, addToCart(product(2, '3')));

    expect(state.products.map((p) => p.id)).toEqual([1, 2]);
    expect(state.total).toBe(43);
  });

  it('ignores a product that is already in the cart', () => {
    let state = reducer(undefined, addToCart(product(1, '40')));
    state = reducer(state, addToCart(product(1, '40')));

    expect(state.products).toHaveLength(1);
    expect(state.total).toBe(40);
  });

  it('empties the cart on clearCart', () => {
    let state = reducer(undefined, addToCart(product(1, '40')));
    state = reducer(state, clearCart());

    expect(state).toMatchObject({ products: [], total: 0 });
  });
});
