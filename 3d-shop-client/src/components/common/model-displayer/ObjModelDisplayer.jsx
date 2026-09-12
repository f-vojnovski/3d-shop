import { Suspense } from 'react';
import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { API_URL } from '../../../consts';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';
import CameraSync from './cameraLink';
import SceneLighting from './SceneLighting';

const ObjModel = ({ fileUrl }) => {
  const obj = useLoader(OBJLoader, fileUrl);
  const { scale, center } = useFitToView(obj);

  return (
    <group scale={scale}>
      <group position={center}>
        <primitive object={obj} />
      </group>
    </group>
  );
};

const ObjModelDisplayer = ({ fileUrl, isLocalFile, probeRef, sync }) => (
  <Canvas>
    <SceneLighting format="obj" />

    {/* Loading suspends: without a boundary here it reaches the app shell. */}
    <Suspense fallback={null}>
      <ObjModel fileUrl={isLocalFile ? fileUrl : `${API_URL}${fileUrl}`} />
    </Suspense>

    <OrbitControls makeDefault />
    {probeRef && <CameraProbe probeRef={probeRef} />}
    {sync && <CameraSync {...sync} />}
  </Canvas>
);

export default ObjModelDisplayer;
