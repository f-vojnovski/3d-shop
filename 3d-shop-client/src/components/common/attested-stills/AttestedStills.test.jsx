import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AttestedStills from './AttestedStills';

const still = (index) => ({
  url: `https://example.test/still-${index}.png`,
  sort: index,
  camera: { position: [3.2, 1.8, 4.1], target: [0, 0, 0], fov: 75 },
  attestation_url: `/api/previews/${index + 1}/attestation`,
});

const product = (overrides = {}) => ({
  name: 'Half-track',
  preview_mode: 'attested_stills',
  preview_status: 'ready',
  preview_error: null,
  product_status: 'not-purchased',
  preview_images: [still(0), still(1)],
  ...overrides,
});

describe('AttestedStills', () => {
  it('shows the first still and a thumbnail per angle', () => {
    render(<AttestedStills product={product()} />);

    expect(screen.getByAltText('Half-track, view 1')).toHaveAttribute(
      'src',
      'https://example.test/still-0.png'
    );
    expect(screen.getAllByRole('button', { name: /^View \d$/ })).toHaveLength(2);
  });

  it('switches the shown still when another angle is picked', async () => {
    render(<AttestedStills product={product()} />);

    await userEvent.click(screen.getByRole('button', { name: 'View 2' }));

    expect(screen.getByAltText('Half-track, view 2')).toHaveAttribute(
      'src',
      'https://example.test/still-1.png'
    );
  });

  it('states the guarantee alongside the stills', () => {
    render(<AttestedStills product={product()} />);

    expect(screen.getByText(/rendered by our server from the model on sale/i)).toBeInTheDocument();
  });

  it('says a render is under way rather than showing an empty frame', () => {
    render(
      <AttestedStills product={product({ preview_status: 'rendering', preview_images: [] })} />
    );

    expect(screen.getByText(/rendering previews/i)).toBeInTheDocument();
  });

  it('keeps the failure reason from buyers but shows it to the owner', () => {
    const failed = {
      preview_status: 'failed',
      preview_images: [],
      preview_error: 'Every rendered image was blank.',
    };

    const { unmount } = render(<AttestedStills product={product(failed)} />);
    expect(screen.getByText(/no previews are available/i)).toBeInTheDocument();
    expect(screen.queryByText(failed.preview_error)).not.toBeInTheDocument();
    unmount();

    render(<AttestedStills product={product({ ...failed, product_status: 'owner' })} />);
    expect(screen.getByText(failed.preview_error)).toBeInTheDocument();
  });
});
