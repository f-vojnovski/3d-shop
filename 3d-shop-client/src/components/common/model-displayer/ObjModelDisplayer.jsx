import { Suspense } from 'react';
import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { API_URL } from '../../../consts';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';

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

const ObjModelDisplayer = ({ fileUrl, isLocalFile, probeRef }) => (
  <Canvas>
    {/* Neutral, but not flat: untextured geometry needs a strong angled key to read as 3D. */}
    <ambientLight intensity={0.3} />
    <directionalLight color="white" position={[4, 5, 3]} intensity={1.1} />
    <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />

    {/* Loading suspends: without a boundary here it reaches the app shell. */}
    <Suspense fallback={null}>
      <ObjModel fileUrl={isLocalFile ? fileUrl : `${API_URL}${fileUrl}`} />
    </Suspense>

    <OrbitControls makeDefault />
    {probeRef && <CameraProbe probeRef={probeRef} />}
  </Canvas>
);

export default ObjModelDisplayer;
