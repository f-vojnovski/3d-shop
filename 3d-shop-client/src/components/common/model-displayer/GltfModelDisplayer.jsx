import { Suspense } from 'react';
import { Canvas } from '@react-three/fiber';
import { OrbitControls, useGLTF } from '@react-three/drei';
import { API_URL } from '../../../consts';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';
import CameraSync from './cameraLink';
import SceneLighting from './SceneLighting';

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

const GltfModelDisplayer = ({ fileUrl, isLocalFile, probeRef, sync }) => (
  <Canvas>
    <SceneLighting format="gltf" />

    {/* Loading suspends: without a boundary here it reaches the app shell. */}
    <Suspense fallback={null}>
      <GltfModel fileUrl={isLocalFile ? fileUrl : `${API_URL}${fileUrl}`} />
    </Suspense>

    <OrbitControls makeDefault />
    {probeRef && <CameraProbe probeRef={probeRef} />}
    {sync && <CameraSync {...sync} />}
  </Canvas>
);

export default GltfModelDisplayer;
