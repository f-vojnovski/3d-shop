import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AttestedStills from './AttestedStills';

const still = (format, index, wireframe = null) => ({
  id: `${format}-${index}`,
  url: `https://example.test/${format}-${index}.png`,
  sort: index,
  source_format: format,
  attestation_url: `/api/previews/${format}${index}/attestation`,
  wireframe,
});

const wireframeOf = (format, index) => ({
  id: `${format}-${index}-wire`,
  url: `https://example.test/${format}-${index}-wire.png`,
  attestation_url: `/api/previews/${format}${index}wire/attestation`,
});

const withWireframes = (format, count) =>
  preview(format, count, {
    images: Array.from({ length: count }, (_, index) =>
      still(format, index, wireframeOf(format, index))),
  });

const preview = (format, count, overrides = {}) => ({
  format,
  angles: Array.from({ length: count }, () => ({ fov: 75 })),
  status: 'ready',
  error: null,
  images: Array.from({ length: count }, (_, index) => still(format, index)),
  ...overrides,
});

const product = (previews, sellerImages = []) => ({
  name: 'Half-track',
  preview_mode: 'attested_stills',
  product_status: 'not-purchased',
  previews,
  seller_images: sellerImages,
});

const sellerImage = (index) => ({
  id: `seller-${index}`,
  url: `https://example.test/seller-${index}.jpg`,
  sort: index,
});

describe('AttestedStills', () => {
  it('shows the first still of the first format that has any', () => {
    render(<AttestedStills product={product([preview('obj', 2)])} />);

    expect(screen.getByAltText('Half-track, .obj view 1')).toHaveAttribute(
      'src',
      'https://example.test/obj-0.png'
    );
  });

  it('swaps in the wireframe drawn at the same camera', async () => {
    render(<AttestedStills product={product([withWireframes('obj', 2)])} />);

    await userEvent.click(screen.getByRole('button', { name: 'Wireframe' }));

    expect(screen.getByAltText('Half-track, .obj view 1 wireframe')).toHaveAttribute(
      'src',
      'https://example.test/obj-0-wire.png'
    );
    expect(screen.getByRole('button', { name: 'Wireframe' }))
      .toHaveAttribute('aria-pressed', 'true');
  });

  it('goes back to the shaded view', async () => {
    render(<AttestedStills product={product([withWireframes('obj', 1)])} />);
    await userEvent.click(screen.getByRole('button', { name: 'Wireframe' }));

    await userEvent.click(screen.getByRole('button', { name: 'Wireframe' }));

    expect(screen.getByAltText('Half-track, .obj view 1')).toHaveAttribute(
      'src',
      'https://example.test/obj-0.png'
    );
  });

  // Stills rendered before the wireframe pass existed have none to show.
  it('offers no toggle when the still has no wireframe', () => {
    render(<AttestedStills product={product([preview('obj', 2)])} />);

    expect(screen.queryByRole('button', { name: 'Wireframe' })).not.toBeInTheDocument();
  });

  it('keeps the wireframe on while moving between views', async () => {
    render(<AttestedStills product={product([withWireframes('obj', 2)])} />);
    await userEvent.click(screen.getByRole('button', { name: 'Wireframe' }));

    await userEvent.click(screen.getByRole('button', { name: 'View 2' }));

    expect(screen.getByAltText('Half-track, .obj view 2 wireframe')).toHaveAttribute(
      'src',
      'https://example.test/obj-1-wire.png'
    );
  });

  it("offers no wireframe of the seller's own images", async () => {
    render(<AttestedStills product={product([withWireframes('obj', 1)], [sellerImage(0)])} />);

    await userEvent.click(screen.getByRole('tab', { name: 'From the seller' }));

    expect(screen.queryByRole('button', { name: 'Wireframe' })).not.toBeInTheDocument();
  });

  it('offers no format switch when there is only one format', () => {
    render(<AttestedStills product={product([preview('obj', 2)])} />);

    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
  });

  // Each format renders separately, so one model can look different as .obj and as .glb.
  it('switches the gallery between formats', async () => {
    render(<AttestedStills product={product([preview('obj', 1), preview('gltf', 2)])} />);

    await userEvent.click(screen.getByRole('tab', { name: '.gltf' }));

    expect(screen.getByAltText('Half-track, .gltf view 1')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: '.gltf' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: '.obj' })).toHaveAttribute('aria-selected', 'false');
  });

  it('starts from the first view again after switching format', async () => {
    render(<AttestedStills product={product([preview('obj', 3), preview('gltf', 2)])} />);
    await userEvent.click(screen.getByRole('button', { name: 'View 3' }));

    await userEvent.click(screen.getByRole('tab', { name: '.gltf' }));

    expect(screen.getByAltText('Half-track, .gltf view 1')).toBeInTheDocument();
  });

  it('names the format the stills came from', () => {
    render(<AttestedStills product={product([preview('gltf', 1)])} />);

    expect(screen.getByText(/from the \.gltf file on sale/)).toBeInTheDocument();
  });

  it('says a render is under way rather than showing an empty frame', () => {
    render(
      <AttestedStills product={product([preview('obj', 0, { status: 'rendering' })])} />
    );

    expect(screen.getByText(/rendering previews/i)).toBeInTheDocument();
  });

  it('prefers a format that has stills over one still rendering', () => {
    render(
      <AttestedStills
        product={product([preview('obj', 0, { status: 'rendering' }), preview('gltf', 1)])}
      />
    );

    expect(screen.getByAltText('Half-track, .gltf view 1')).toBeInTheDocument();
  });

  it('separates having no angles from having tried and failed', () => {
    const none = preview('obj', 0, { status: 'none', angles: [] });

    render(<AttestedStills product={{ ...product([none]), product_status: 'owner' }} />);

    expect(screen.getByText(/\.obj file has no camera angles/)).toBeInTheDocument();
    expect(screen.getByText(/capture at least one camera angle/i)).toBeInTheDocument();
  });

  it('keeps the failure reason from buyers but shows it to the owner', () => {
    const failed = preview('obj', 0, { status: 'failed', error: 'Every image was blank.' });

    const { unmount } = render(<AttestedStills product={product([failed])} />);
    expect(screen.getByText(/could not be rendered from the \.obj file/)).toBeInTheDocument();
    expect(screen.queryByText('Every image was blank.')).not.toBeInTheDocument();
    unmount();

    render(<AttestedStills product={{ ...product([failed]), product_status: 'owner' }} />);
    expect(screen.getByText('Every image was blank.')).toBeInTheDocument();
  });

  // Labelling the seller's images reads as a disclaimer against them; the tab
  // already says whose they are.
  it("leaves the seller's own images unlabelled", async () => {
    render(<AttestedStills product={product([preview('obj', 1)], [sellerImage(0)])} />);

    await userEvent.click(screen.getByRole('tab', { name: 'From the seller' }));

    expect(screen.queryByText(/System-rendered/)).not.toBeInTheDocument();
    expect(screen.queryByText(/supplied by the seller/i)).not.toBeInTheDocument();
    expect(screen.getByAltText('Half-track, image 1 from the seller')).toBeInTheDocument();
  });

  it('offers no seller tab when there are no seller images', () => {
    render(<AttestedStills product={product([preview('obj', 1), preview('gltf', 1)])} />);

    expect(screen.queryByRole('tab', { name: 'From the seller' })).not.toBeInTheDocument();
  });

  it("shows the attested stills first, not the seller's images", () => {
    render(<AttestedStills product={product([preview('obj', 1)], [sellerImage(0)])} />);

    expect(screen.getByText(/System-rendered/)).toBeInTheDocument();
  });

  it('still offers the seller tab when a render failed', async () => {
    const failed = preview('obj', 0, { status: 'failed', error: 'blank' });

    render(<AttestedStills product={product([failed], [sellerImage(0)])} />);
    await userEvent.click(screen.getByRole('tab', { name: 'From the seller' }));

    expect(screen.getByAltText('Half-track, image 1 from the seller')).toBeInTheDocument();
  });
});
