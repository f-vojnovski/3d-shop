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

const renderCart = (products) => {
  const store = configureStore({
    reducer: { cart: cartReducer },
    preloadedState: {
      cart: {
        products,
        total: products.reduce((sum, p) => sum + p.price_cents, 0),
        status: 'idle',
        error: null,
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
});
