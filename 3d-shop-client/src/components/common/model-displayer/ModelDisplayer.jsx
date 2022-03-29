import styles from "./ModelDisplayer.module.css";
import { Canvas } from "@react-three/fiber";
import { OrbitControls } from "@react-three/drei";

const ModelDisplayer = () => {
  return (
    <Canvas>
      <ambientLight intensity={0.1} />
      <directionalLight color="red" position={[0, 0, 5]} />
      <mesh>
        <boxGeometry />
        <meshStandardMaterial color="hotpink"/>
      </mesh>
      <OrbitControls></OrbitControls>
    </Canvas>
  );
};

export default ModelDisplayer;
