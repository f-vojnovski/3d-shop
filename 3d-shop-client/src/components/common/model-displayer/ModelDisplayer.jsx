import { Canvas } from "@react-three/fiber";
import { OrbitControls } from "@react-three/drei";

const ModelDisplayer = () => {
  return (
    <Canvas>
      <ambientLight intensity={0.1} />
      <directionalLight color="red" position={[3, 0, 3]} intensity={0.2}/>
      <directionalLight color="blue" position={[-3, -3, -3]} />
      <mesh>
        <boxGeometry />
        <meshStandardMaterial color="hotpink"/>
      </mesh>
      <OrbitControls></OrbitControls>
    </Canvas>
  );
};

export default ModelDisplayer;
