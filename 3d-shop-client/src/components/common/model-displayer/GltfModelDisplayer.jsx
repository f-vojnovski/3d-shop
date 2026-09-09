import { Canvas } from '@react-three/fiber';
import { OrbitControls, useGLTF } from '@react-three/drei';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';
import StudioEnvironment from './StudioEnvironment';

const GltfModelDisplayer = (props) => {
  let fileUrl;
  if (!props.isLocalFile) {
    fileUrl = `${API_URL}${props.fileUrl}`;
  } else {
    fileUrl = props.fileUrl;
  }

  const gltf = useGLTF(fileUrl);
  const { scale, center } = useFitToView(gltf.scene);

  let content = (
    <Canvas>
      {/* Real materials: IBL plus a soft key, not the .obj path's flat ambient. */}
      <StudioEnvironment />
      <directionalLight color="white" position={[4, 5, 3]} intensity={0.6} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.2} />
      <group scale={scale}>
        <group position={center}>
          <primitive object={gltf.scene} />
        </group>
      </group>
      <OrbitControls makeDefault></OrbitControls>
      {props.probeRef && <CameraProbe probeRef={props.probeRef} />}
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default GltfModelDisplayer;
