import { render, screen } from '@testing-library/react';
import FileHistory from './FileHistory';

const version = (overrides = {}) => ({
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

describe('FileHistory', () => {
  it('says nothing when a file was never replaced', () => {
    const { container } = render(<FileHistory format="obj" replaced={[]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('reports when the file stopped being the one on sale', () => {
    render(<FileHistory format="obj" replaced={[version()]} />);

    expect(screen.getByText('.obj file replaced once')).toBeInTheDocument();
    expect(screen.getByText(/Until 14 March 2026/)).toBeInTheDocument();
    expect(screen.getByText(/213,347 faces/)).toBeInTheDocument();
  });

  it("shows the seller's own account of what changed", () => {
    render(<FileHistory format="obj" replaced={[version()]} />);

    expect(screen.getByText('“Fixed the scale.”')).toBeInTheDocument();
  });

  it('copes with a replacement nobody explained', () => {
    render(<FileHistory format="obj" replaced={[version({ note: null })]} />);

    expect(screen.getByText(/Until 14 March 2026/)).toBeInTheDocument();
  });

  /** The old previews are the evidence, so they stay reachable. */
  it('keeps the superseded previews and links each to its record', () => {
    render(<FileHistory format="gltf" replaced={[version()]} />);

    const first = screen.getByAltText('Previous .gltf view 1');

    expect(first).toHaveAttribute('src', 'https://example.test/old-0.png');
    expect(first.closest('a')).toHaveAttribute('href', '/api/previews/1/attestation');
    expect(screen.getByAltText('Previous .gltf view 2')).toBeInTheDocument();
  });

  it('counts more than one replacement', () => {
    render(
      <FileHistory
        format="obj"
        replaced={[version(), version({ replaced_at: '2026-01-02T10:00:00Z', sha256: 'def456' })]}
      />
    );

    expect(screen.getByText('.obj file replaced 2 times')).toBeInTheDocument();
    expect(screen.getByText(/Until 2 January 2026/)).toBeInTheDocument();
  });
});
