import { Canvas } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { useGLTF } from '@react-three/drei/core';
import { API_URL } from '../../../consts';
import { Fragment } from 'react';
import useFitToView from './useFitToView';

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
      {/* Neutral lighting: a coloured key light would misrepresent the asset,
          but flat lighting hides the form of an untextured model, so this keeps
          low ambient and a strong angled key to give the geometry shading. */}
      <ambientLight intensity={0.3} />
      <directionalLight color="white" position={[4, 5, 3]} intensity={1.1} />
      <directionalLight color="white" position={[-4, -2, -4]} intensity={0.35} />
      <group scale={scale}>
        <group position={center}>
          <primitive object={gltf.scene} />
        </group>
      </group>
      <OrbitControls></OrbitControls>
    </Canvas>
  );

  return <Fragment>{content}</Fragment>;
};

export default GltfModelDisplayer;
