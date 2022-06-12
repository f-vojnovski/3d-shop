import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';

const ModelDisplayer = (props) => {
  let fileUrl;
  if (!props.isLocalFile) {
    fileUrl = `${API_URL}${props.fileUrl}`;
  } else {
    fileUrl = props.fileUrl;
  }
  const obj = useLoader(OBJLoader, fileUrl);

  let content = (
    <Canvas>
      <ambientLight intensity={0.1} />
      <directionalLight color="yellow" position={[3, 0, 3]} intensity={0.2} />
      <directionalLight color="blue" position={[0, 0, 0]} />
      <mesh>
        <primitive object={obj}></primitive>
      </mesh>
      <OrbitControls></OrbitControls>
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default ModelDisplayer;
