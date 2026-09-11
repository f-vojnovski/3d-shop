import { render, screen } from '@testing-library/react';
import BundleContents from './BundleContents';

const bundle = (overrides = {}) => ({
  entry: 'car/car.obj',
  digest: 'a'.repeat(64),
  bytes: 6446862,
  files: [
    { path: 'car/car.mtl', sha256: 'b'.repeat(64), bytes: 2065 },
    { path: 'car/car.obj', sha256: 'c'.repeat(64), bytes: 21674108 },
    { path: 'car/textures/body.png', sha256: 'd'.repeat(64), bytes: 875989 },
  ],
  ...overrides,
});

describe('BundleContents', () => {
  it('names every file in the download and what it weighs', () => {
    render(<BundleContents bundle={bundle()} format="obj" />);

    expect(screen.getByText('3 files in the .obj download')).toBeInTheDocument();
    expect(screen.getByText('6.1 MB zipped')).toBeInTheDocument();
    expect(screen.getByText('car/textures/body.png')).toBeInTheDocument();
    expect(screen.getByText('20.7 MB')).toBeInTheDocument();
  });

  it('marks the model the previews came from', () => {
    render(<BundleContents bundle={bundle()} format="obj" />);

    expect(screen.getByText('car/car.obj').closest('li').className).toMatch(/model/);
    expect(screen.getByText('car/car.mtl').closest('li').className).not.toMatch(/model/);
  });

  it('carries the full checksum even though it shows a short one', () => {
    render(<BundleContents bundle={bundle()} format="obj" />);

    expect(screen.getByText('car/car.mtl')).toHaveAttribute('title', 'b'.repeat(64));
  });

  it('renders nothing for a bare model file', () => {
    const { container } = render(<BundleContents bundle={null} format="obj" />);

    expect(container).toBeEmptyDOMElement();
  });
});
