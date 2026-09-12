import { Suspense, useMemo } from 'react';
import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { Mesh, MeshPhongMaterial } from 'three';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import { API_URL } from '../../../consts';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';
import CameraSync from './cameraLink';
import SceneLighting from './SceneLighting';

const StlModel = ({ fileUrl }) => {
  const geometry = useLoader(STLLoader, fileUrl);

  // The loader returns geometry, and fitToView measures objects. Built the same
  // way the render harness builds it, so framing here matches what it draws.
  const mesh = useMemo(
    () => new Mesh(geometry, new MeshPhongMaterial({ color: 0xb9bec7 })),
    [geometry],
  );
  const { scale, center } = useFitToView(mesh);

  return (
    <group scale={scale}>
      <group position={center}>
        <primitive object={mesh} />
      </group>
    </group>
  );
};

const StlModelDisplayer = ({ fileUrl, isLocalFile, probeRef, sync }) => (
  <Canvas>
    <SceneLighting format="stl" />

    <Suspense fallback={null}>
      <StlModel fileUrl={isLocalFile ? fileUrl : `${API_URL}${fileUrl}`} />
    </Suspense>

    <OrbitControls makeDefault />
    {probeRef && <CameraProbe probeRef={probeRef} />}
    {sync && <CameraSync {...sync} />}
  </Canvas>
);

export default StlModelDisplayer;
