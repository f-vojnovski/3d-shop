import { Canvas, useLoader } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { useGLTF } from '@react-three/drei/core';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader';

const GltfModelDisplayer = (props) => {
  let fileUrl;
  if (!props.isLocalFile) {
    fileUrl = `${API_URL}${props.fileUrl}`;
  } else {
    fileUrl = props.fileUrl;
  }
  
  const gltf = useGLTF(fileUrl);

  let content = (
    <Canvas>
      <ambientLight intensity={0.1} />
      <directionalLight color="yellow" position={[3, 0, 3]} intensity={0.2} />
      <directionalLight color="white" position={[0, 0, 0]} />
      <>
        <primitive object={gltf.scene} scale={0.4} />
      </>
      <OrbitControls></OrbitControls>
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default GltfModelDisplayer;
