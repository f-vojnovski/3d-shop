import { render, screen } from '@testing-library/react';
import ModelFacts from './ModelFacts';

const facts = (overrides = {}) => ({
  vertices: 162766,
  faces: 213347,
  topology: 'triangles',
  normals: true,
  uvs: true,
  materials: 29,
  textures: [{ width: 512, height: 512 }, { width: 2048, height: 2048 }],
  bounds: { min: [0, 0, 0], max: [2.5417, 1.1493, 4.3571], size: [2.5417, 1.1493, 4.3571] },
  rigged: false,
  animated: false,
  ...overrides,
});

const row = (name) => screen.getByText(name).parentElement.textContent;

describe('ModelFacts', () => {
  it('names the file the numbers were measured from', () => {
    render(<ModelFacts facts={facts()} format="obj" />);

    expect(screen.getByText('Measured from the .obj file')).toBeInTheDocument();
  });

  it('shows counts with separators and the topology', () => {
    render(<ModelFacts facts={facts()} format="gltf" />);

    expect(row('Faces')).toContain('213,347 (triangles)');
    expect(row('Vertices')).toContain('162,766');
  });

  it('reports the bounding box to two decimals', () => {
    render(<ModelFacts facts={facts()} format="gltf" />);

    expect(row('Bounding box')).toContain('2.54 × 1.15 × 4.36');
  });

  it('summarises textures by count and the largest dimension', () => {
    render(<ModelFacts facts={facts()} format="gltf" />);

    expect(row('Textures')).toContain('2, up to 2048px');
  });

  // An .obj arrives without its .mtl, so those rows have nothing to say.
  it('leaves out materials and textures when the file carries none', () => {
    render(<ModelFacts facts={facts({ materials: null, textures: [] })} format="obj" />);

    expect(screen.queryByText('Materials')).not.toBeInTheDocument();
    expect(screen.queryByText('Textures')).not.toBeInTheDocument();
    expect(screen.getByText('Faces')).toBeInTheDocument();
  });

  it('mentions rigging and animation only when present', () => {
    const { unmount } = render(<ModelFacts facts={facts()} format="gltf" />);
    expect(screen.queryByText('Rigged')).not.toBeInTheDocument();
    unmount();

    render(<ModelFacts facts={facts({ rigged: true, animated: true })} format="gltf" />);
    expect(screen.getByText('Rigged')).toBeInTheDocument();
    expect(screen.getByText('Animated')).toBeInTheDocument();
  });

  it('renders nothing at all when a product has no measurements', () => {
    const { container } = render(<ModelFacts facts={null} format="obj" />);

    expect(container).toBeEmptyDOMElement();
  });

  it('still says whether UVs and normals exist when they do not', () => {
    render(<ModelFacts facts={facts({ uvs: false, normals: false })} format="obj" />);

    expect(row('UVs')).toContain('No');
    expect(row('Normals')).toContain('No');
  });
});
