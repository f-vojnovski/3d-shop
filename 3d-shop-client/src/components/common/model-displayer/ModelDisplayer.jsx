import { Canvas } from "@react-three/fiber";
import { OrbitControls } from "@react-three/drei";
import * as THREE from 'three'

const ModelDisplayer = () => {
  let fileUrl =
    "http://127.0.0.1:8000/storage/obj_files/627ca2ce77c65.obj";

  const loader = new THREE.FileLoader();

  loader.load(
    // resource URL
    fileUrl,

    // onLoad callback
    function (data) {
      // output the text to the console
      console.log(data);
    },

    // onProgress callback
    function (xhr) {
      console.log(
        (xhr.loaded / xhr.total) * 100 + "% loaded"
      );
    },

    // onError callback
    function (err) {
      console.error("An error happened");
    }
  );

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
        <boxGeometry />
        <meshStandardMaterial color="hotpink" />
      </mesh>
      <OrbitControls></OrbitControls>
    </Canvas>
  );
};

export default ModelDisplayer;
