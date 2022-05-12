import { Canvas, useLoader } from "@react-three/fiber";
import { OrbitControls } from "@react-three/drei";
import * as THREE from "three";
import { OBJLoader } from "three/examples/jsm/loaders/OBJLoader";
import { Suspense } from "react";

const ModelDisplayer = () => {
  const fileUrl =
    "http://127.0.0.1:8000/storage/obj_files/627cd53ad8585.obj";

  const obj = useLoader(OBJLoader, fileUrl);

  return (
    <Canvas>
      <ambientLight intensity={0.1} />
      <directionalLight
        color="red"
        position={[3, 0, 3]}
        intensity={0.2}
      />
      <directionalLight
        color="blue"
        position={[-3, -3, -3]}
      />
      <mesh>
          <primitive object={obj}></primitive>
      </mesh>
      <OrbitControls></OrbitControls>
    </Canvas>
  );
};

export default ModelDisplayer;
