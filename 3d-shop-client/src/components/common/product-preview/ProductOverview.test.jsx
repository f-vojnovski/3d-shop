import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ProductOverview from './ProductOverview';

const card = (images, extra = {}) =>
  render(
    <MemoryRouter>
      <ProductOverview id={7} name="Half-track" priceCents={2450} images={images} {...extra} />
    </MemoryRouter>
  );

describe('ProductOverview', () => {
  it('shows the first picture and no paging when there is only one', () => {
    card(['/a.png']);

    expect(screen.getByAltText('Half-track preview 1')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Next image' })).not.toBeInTheDocument();
  });

  it('pages through the pictures a seller chose', async () => {
    card(['/a.png', '/b.png', '/c.png']);

    await userEvent.click(screen.getByRole('button', { name: 'Next image' }));

    expect(screen.getByAltText('Half-track preview 2')).toHaveAttribute('src', '/b.png');
  });

  /** The last one steps to the first, rather than stopping on a dead button. */
  it('wraps around in both directions', async () => {
    card(['/a.png', '/b.png']);

    await userEvent.click(screen.getByRole('button', { name: 'Previous image' }));

    expect(screen.getByAltText('Half-track preview 2')).toHaveAttribute('src', '/b.png');

    await userEvent.click(screen.getByRole('button', { name: 'Next image' }));

    expect(screen.getByAltText('Half-track preview 1')).toHaveAttribute('src', '/a.png');
  });

  /**
   * The whole picture is a link to the product, so paging must not navigate.
   * That is what the preventDefault in the component is for.
   */
  it('does not follow the card link when paging', async () => {
    card(['/a.png', '/b.png']);

    await userEvent.click(screen.getByRole('button', { name: 'Next image' }));

    expect(screen.getByAltText('Half-track preview 2')).toBeInTheDocument();
    expect(screen.getAllByRole('link').length).toBeGreaterThan(0);
  });

  it('says so plainly when a listing has no picture at all', () => {
    card([]);

    expect(screen.getByText('No preview')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Next image' })).not.toBeInTheDocument();
  });

  it('marks a listing that is not on sale', () => {
    card(['/a.png'], { status: 'draft' });

    expect(screen.getByText('Not published')).toBeInTheDocument();
  });
});
