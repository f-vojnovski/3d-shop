import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';
import useFitToView from './useFitToView';

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
      {/* Neutral lighting: a coloured key light would misrepresent the asset,
          but flat lighting hides the form of an untextured model, so this keeps
          low ambient and a strong angled key to give the geometry shading. */}
      <ambientLight intensity={0.3} />
      <directionalLight color="white" position={[4, 5, 3]} intensity={1.1} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />
      <group scale={scale}>
        <group position={center}>
          <primitive object={obj}></primitive>
        </group>
      </group>
      <OrbitControls></OrbitControls>
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default ObjModelDisplayer;
