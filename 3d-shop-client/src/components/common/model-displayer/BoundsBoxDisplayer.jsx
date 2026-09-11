import { useMemo } from 'react';
import { Canvas } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { BoxGeometry, DoubleSide, Mesh } from 'three';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';

/**
 * A box the size of the measured bounding box, for aiming a camera at a model
 * the viewer has not bought. fitToView scales by the longest side of an
 * object's bounds, and this box shares them, so a camera framed here lands
 * where it was aimed on the real mesh.
 */
const BoundsBox = ({ size }) => {
  const geometry = useMemo(() => new BoxGeometry(...size), [size]);
  const mesh = useMemo(() => new Mesh(geometry), [geometry]);
  const { scale, center } = useFitToView(mesh);

  return (
    <group scale={scale}>
      <group position={center}>
        {/* Opaque and two-sided: a see-through box loses its near faces to
            back-face culling and reads as an open trough. */}
        <mesh geometry={geometry}>
          <meshLambertMaterial color="#7c8796" side={DoubleSide} />
        </mesh>
        <lineSegments>
          <edgesGeometry args={[geometry]} />
          <lineBasicMaterial color="#eef2f7" />
        </lineSegments>
      </group>
    </group>
  );
};

const BoundsBoxDisplayer = ({ size, probeRef }) => (
  // Three-quarter to start: down an axis a box is a flat rectangle, which is
  // what made the outline unreadable.
  <Canvas camera={{ position: [3.4, 2.6, 3.4], fov: 75 }}>
    <ambientLight intensity={0.6} />
    <directionalLight color="white" position={[4, 6, 3]} intensity={1} />
    <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />

    <BoundsBox size={size} />

    <OrbitControls makeDefault />
    <CameraProbe probeRef={probeRef} />
  </Canvas>
);

export default BoundsBoxDisplayer;
