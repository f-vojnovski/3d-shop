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
  it('says nothing about a file that could not be measured', () => {
    const { container } = render(
      <ModelFacts
        format="fbx"
        facts={{
          vertices: null,
          faces: null,
          topology: 'unknown',
          normals: false,
          uvs: false,
          materials: null,
          textures: [],
          bounds: null,
          rigged: false,
          animated: false,
        }}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('warns when the formats are not the same model', () => {
    render(
      <ModelFacts
        format="gltf"
        facts={{ faces: 100, vertices: 300, topology: 'triangles', normals: true, uvs: true, materials: 1, textures: [], bounds: null, rigged: false, animated: false }}
        agreement={{ compared: ['gltf', 'stl'], agrees: false, differences: ['Face counts differ: .gltf 100, .stl 1.'] }}
      />,
    );

    expect(screen.getByText(/not the same model/i)).toBeInTheDocument();
    expect(screen.getByText(/Face counts differ/)).toBeInTheDocument();
  });

  it('stays quiet when the formats agree', () => {
    render(
      <ModelFacts
        format="gltf"
        facts={{ faces: 100, vertices: 300, topology: 'triangles', normals: true, uvs: true, materials: 1, textures: [], bounds: null, rigged: false, animated: false }}
        agreement={{ compared: ['gltf', 'stl'], agrees: true, differences: [] }}
      />,
    );

    expect(screen.queryByText(/not the same model/i)).not.toBeInTheDocument();
  });

  it('names the file the numbers were measured from', () => {
    render(<ModelFacts facts={facts()} format="obj" />);

    expect(screen.getByText('Measured from the .obj file')).toBeInTheDocument();
  });

  it('shows counts with separators and the topology', () => {
    render(<ModelFacts facts={facts()} format="gltf" />);

    expect(row('Faces')).toContain('213,347 (triangles)');
    expect(row('Vertices')).toContain('162,766');
  });

  it('reports the bounding box to two decimals, in metres for a glTF', () => {
    render(<ModelFacts facts={facts()} format="gltf" />);

    expect(row('Bounding box')).toContain('2.54 × 1.15 × 4.36 m');
  });

  /** Only glTF fixes a unit, so claiming metres anywhere else would be made up. */
  it('names the textures the download does not contain', () => {
    render(
      <ModelFacts facts={facts()} format="obj" missing={['textures/body.png', 'textures/trim.png']} />
    );

    expect(screen.getByText(/asks for 2 textures the download does not contain/)).toBeInTheDocument();
    expect(screen.getByText('textures/body.png')).toBeInTheDocument();
  });

  it('stops listing missing textures after six', () => {
    const missing = Array.from({ length: 63 }, (_, index) => `textures/stone_${index}.jpg`);

    render(<ModelFacts facts={facts()} format="obj" missing={missing} />);

    expect(screen.getByText('textures/stone_5.jpg')).toBeInTheDocument();
    expect(screen.queryByText('textures/stone_6.jpg')).not.toBeInTheDocument();
    expect(screen.getByText('and 57 more')).toBeInTheDocument();
  });

  it('says nothing when every texture is present', () => {
    render(<ModelFacts facts={facts()} format="obj" missing={null} />);

    expect(screen.queryByText(/does not contain/)).not.toBeInTheDocument();
  });

  it('does not claim metres for a format that records no unit', () => {
    render(<ModelFacts facts={facts()} format="obj" />);

    expect(row('Bounding box')).toContain('2.54 × 1.15 × 4.36 units');
    expect(row('Bounding box')).not.toContain(' m');
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
