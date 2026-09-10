import { configureStore } from '@reduxjs/toolkit';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Provider } from 'react-redux';
import AddToCartButton from './AddToCartButton';
import cartReducer from '../../../../service/features/cartSlice';
import toastsReducer from '../../../../service/features/toastSlice';

const product = (overrides = {}) => ({
  id: 1,
  name: 'Concept car',
  price_cents: 2450,
  product_status: 'not-purchased',
  unlisted: false,
  ...overrides,
});

const mount = (value) => {
  const store = configureStore({
    reducer: { cart: cartReducer, toasts: toastsReducer },
  });

  render(
    <Provider store={store}>
      <AddToCartButton product={value} />
    </Provider>
  );

  return store;
};

describe('AddToCartButton', () => {
  it('adds an unowned product to the cart', async () => {
    const store = mount(product());

    await userEvent.click(screen.getByRole('button', { name: /add to cart/i }));

    expect(store.getState().cart.products).toHaveLength(1);
    expect(store.getState().cart.total).toBe(2450);
  });

  it('refuses a withdrawn listing', () => {
    mount(product({ unlisted: true }));

    expect(screen.getByRole('button', { name: 'No longer for sale' })).toBeDisabled();
  });

  // Withdrawing a listing must not change what a buyer who paid for it is told.
  it('still tells a buyer they own a withdrawn product', () => {
    mount(product({ unlisted: true, product_status: 'purchased' }));

    expect(screen.getByRole('button', { name: /you have purchased/i })).toBeInTheDocument();
  });

  it('still tells the seller it is theirs', () => {
    mount(product({ unlisted: true, product_status: 'owner' }));

    expect(screen.getByRole('button', { name: /you are the owner/i })).toBeInTheDocument();
  });

  it('does not add the same product twice', async () => {
    const store = mount(product());

    await userEvent.click(screen.getByRole('button', { name: /add to cart/i }));

    expect(screen.getByRole('button', { name: 'Product is in cart' })).toBeDisabled();
    expect(store.getState().cart.products).toHaveLength(1);
  });
});
