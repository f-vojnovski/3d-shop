import StudioEnvironment from './StudioEnvironment';

// The buyer preview sits beside the model it was cut down from, so the two have
// to be lit identically or the seller is comparing lighting, not geometry.
// Untextured .obj and .stl need a strong angled key to read as 3D; glTF carries
// real materials and wants image-based light instead.
const SceneLighting = ({ format }) =>
  format === 'gltf' || format === 'glb' ? (
    <>
      <StudioEnvironment />
      <directionalLight color="white" position={[4, 5, 3]} intensity={0.6} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.2} />
    </>
  ) : (
    <>
      <ambientLight intensity={0.3} />
      <directionalLight color="white" position={[4, 5, 3]} intensity={1.1} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />
    </>
  );

export default SceneLighting;
