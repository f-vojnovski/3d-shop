import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';
import useFitToView from './useFitToView';
import CameraProbe from './CameraProbe';

const ObjModelDisplayer = (props) => {
  let fileUrl;
  if (!props.isLocalFile) {
    fileUrl = `${API_URL}${props.fileUrl}`;
  } else {
    fileUrl = props.fileUrl;
  }
  const obj = useLoader(OBJLoader, fileUrl);
  const { scale, center } = useFitToView(obj);

  let content = (
    <Canvas>
      {/* Neutral, but not flat: untextured geometry needs a strong angled key to read as 3D. */}
      <ambientLight intensity={0.3} />
      <directionalLight color="white" position={[4, 5, 3]} intensity={1.1} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />
      <group scale={scale}>
        <group position={center}>
          <primitive object={obj}></primitive>
        </group>
      </group>
      <OrbitControls makeDefault></OrbitControls>
      {props.probeRef && <CameraProbe probeRef={props.probeRef} />}
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default ObjModelDisplayer;
