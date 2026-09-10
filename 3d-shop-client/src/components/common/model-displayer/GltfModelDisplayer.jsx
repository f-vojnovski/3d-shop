import { Suspense } from 'react';
import { Canvas } from '@react-three/fiber';
import { OrbitControls, useGLTF } from '@react-three/drei';
import { API_URL } from '../../../consts';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';
import StudioEnvironment from './StudioEnvironment';

const GltfModel = ({ fileUrl }) => {
  const gltf = useGLTF(fileUrl);
  const { scale, center } = useFitToView(gltf.scene);

  return (
    <group scale={scale}>
      <group position={center}>
        <primitive object={gltf.scene} />
      </group>
    </group>
  );
};

const GltfModelDisplayer = ({ fileUrl, isLocalFile, probeRef }) => (
  <Canvas>
    {/* Real materials: IBL plus a soft key, not the .obj path's flat ambient. */}
    <StudioEnvironment />
    <directionalLight color="white" position={[4, 5, 3]} intensity={0.6} />
    <directionalLight color="white" position={[-4, -2, -4]} intensity={0.2} />

    {/* Loading suspends: without a boundary here it reaches the app shell. */}
    <Suspense fallback={null}>
      <GltfModel fileUrl={isLocalFile ? fileUrl : `${API_URL}${fileUrl}`} />
    </Suspense>

    <OrbitControls makeDefault />
    {probeRef && <CameraProbe probeRef={probeRef} />}
  </Canvas>
);

export default GltfModelDisplayer;
