import { configureStore } from '@reduxjs/toolkit';
import { render, screen } from '@testing-library/react';
import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import LandingPage from './LandingPage';

const product = (id, name, overrides = {}) => ({
  id,
  name,
  price_cents: 12900,
  thumbnail_url: `https://example.test/seller-${id}.jpg`,
  previews: [
    {
      format: 'gltf',
      status: 'ready',
      images: [{ id: `${id}-0`, url: `https://example.test/server-${id}.png`, sort: 0 }],
    },
  ],
  ...overrides,
});

const mount = (products, status = 'succeeded') => {
  const store = configureStore({
    reducer: {
      products: (state = { products, status, error: null }) => state,
    },
  });

  render(
    <Provider store={store}>
      <MemoryRouter>
        <LandingPage />
      </MemoryRouter>
    </Provider>
  );
};

describe('LandingPage', () => {
  /**
   * The seller's own thumbnail is the one image on the site that does not
   * support the sentence it would sit beside.
   */
  it('illustrates the claim with a server render, never the seller thumbnail', () => {
    mount([product(1, 'Concept car')]);

    expect(screen.getByAltText('Concept car')).toHaveAttribute(
      'src',
      'https://example.test/server-1.png'
    );
    expect(screen.getByText(/rendered here from the .gltf file on sale/)).toBeInTheDocument();
  });

  it('skips products whose render has not landed', () => {
    mount([
      product(1, 'Still rendering', { previews: [{ format: 'obj', status: 'queued', images: [] }] }),
      product(2, 'Ready'),
    ]);

    expect(screen.getByAltText('Ready')).toHaveAttribute(
      'src',
      'https://example.test/server-2.png'
    );
  });

  it('says so plainly when there is nothing listed', () => {
    mount([]);

    expect(screen.getByText('Nothing is listed yet.')).toBeInTheDocument();
    expect(screen.queryByRole('figure')).not.toBeInTheDocument();
  });
});
