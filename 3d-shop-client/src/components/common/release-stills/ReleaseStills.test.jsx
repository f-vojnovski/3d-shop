import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ReleaseStills from './ReleaseStills';

const release = (overrides = {}) => ({
  replaced_at: '2026-03-14T10:00:00Z',
  note: 'Fixed the scale.',
  sha256: 'abc123',
  facts: { faces: 213347 },
  images: [
    { id: 1, url: 'https://example.test/old-0.png', sort: 0, attestation_url: '/api/previews/1/attestation' },
    { id: 2, url: 'https://example.test/old-1.png', sort: 1, attestation_url: '/api/previews/2/attestation' },
  ],
  ...overrides,
});

const show = (overrides) =>
  render(<ReleaseStills release={release(overrides)} format="gltf" productName="Car" />);

describe('ReleaseStills', () => {
  it('says which release this is and that it is not for sale', () => {
    show();

    expect(screen.getByText(/replaced on 14 March 2026/)).toBeInTheDocument();
    expect(screen.getByText(/not the file\s+on sale/)).toBeInTheDocument();
  });

  it('puts the release on the stage rather than in a thumbnail strip', () => {
    show();

    expect(screen.getByRole('img', { name: /Car, .gltf view 1/ })).toHaveAttribute(
      'src',
      'https://example.test/old-0.png'
    );
  });

  /**
   * The complaint this was built for: the picture used to be a link to the
   * attestation JSON, so looking at an old render opened a file instead.
   */
  it('opens the picture, not the record, when the picture is clicked', async () => {
    show();

    const stage = screen.getByRole('button', { name: /Open full size/ });

    expect(stage.closest('a')).toBeNull();

    await userEvent.click(stage);

    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('keeps the record reachable, but as something that says what it is', () => {
    show();

    expect(
      screen.getByRole('link', { name: 'What this image was rendered from' })
    ).toHaveAttribute('href', '/api/previews/1/attestation');
  });

  it('switches which release image is on the stage', async () => {
    show();

    await userEvent.click(screen.getByRole('button', { name: 'View 2' }));

    expect(screen.getByRole('img', { name: /Car, .gltf view 2/ })).toHaveAttribute(
      'src',
      'https://example.test/old-1.png'
    );
  });

  it('still explains itself when that release was never rendered', () => {
    show({ images: [] });

    expect(screen.getByText('Nothing was rendered from this release.')).toBeInTheDocument();
    expect(screen.getByText(/replaced on 14 March 2026/)).toBeInTheDocument();
  });
});
