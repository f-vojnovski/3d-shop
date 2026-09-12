import { configureStore } from '@reduxjs/toolkit';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import cartReducer from '../../../service/features/cartSlice';
import CheckoutPage from './CheckoutPage';

const product = (id, priceCents) => ({
  id,
  name: `Product ${id}`,
  price_cents: priceCents,
  thumbnail_url: `/storage/thumbnails/${id}.png`,
});

const assign = vi.fn();
let realLocation;

beforeEach(() => {
  assign.mockClear();
  realLocation = window.location;
  Object.defineProperty(window, 'location', {
    configurable: true,
    writable: true,
    value: { ...realLocation, assign },
  });
});

afterEach(() => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    writable: true,
    value: realLocation,
  });
});

const renderCart = (products, checkout = {}) => {
  const store = configureStore({
    reducer: { cart: cartReducer },
    preloadedState: {
      cart: {
        products,
        total: products.reduce((sum, p) => sum + p.price_cents, 0),
        status: 'idle',
        error: null,
        order: null,
        ...checkout,
      },
    },
  });

  render(
    <Provider store={store}>
      <MemoryRouter>
        <CheckoutPage />
      </MemoryRouter>
    </Provider>
  );

  return store;
};

describe('CheckoutPage', () => {
  it('shows what the cart will cost', () => {
    renderCart([product(1, 4000), product(2, 1050)]);

    expect(screen.getByText('Total: $50.50')).toBeInTheDocument();
  });

  it('gives each line a real thumbnail rather than an undefined src', () => {
    renderCart([product(1, 4000)]);

    expect(screen.getByAltText('Product 1 thumbnail')).toHaveAttribute(
      'src',
      '/storage/thumbnails/1.png'
    );
  });

  it('removes a line and recalculates the total', async () => {
    renderCart([product(1, 4000), product(2, 1050)]);

    await userEvent.click(screen.getAllByRole('button', { name: 'Remove' })[0]);

    expect(screen.getByText('Total: $10.50')).toBeInTheDocument();
    expect(screen.queryByText('Product 1')).not.toBeInTheDocument();
  });

  it('says the cart is empty when the last line goes', async () => {
    renderCart([product(1, 4000)]);

    await userEvent.click(screen.getByRole('button', { name: 'Remove' }));

    expect(screen.getByText(/shopping cart is empty/i)).toBeInTheDocument();
  });

  /**
   * The trap: an order opened earlier still names a payment page, so a cart
   * opened on top of one used to leave for the gateway before it could be read.
   */
  it('does not leave for the gateway because an earlier order is still around', () => {
    renderCart([product(1, 4000)], {
      status: 'succeeded',
      order: { id: 9, checkout_url: 'https://pay.example.test/9' },
    });

    expect(assign).not.toHaveBeenCalled();
    expect(screen.getByText('Total: $40.00')).toBeInTheDocument();
  });

  it('reports the attempt on the button rather than leaving the buyer guessing', () => {
    renderCart([product(1, 4000)], { status: 'loading' });

    expect(screen.getByRole('button', { name: /taking you to payment/i })).toBeDisabled();
  });
});
