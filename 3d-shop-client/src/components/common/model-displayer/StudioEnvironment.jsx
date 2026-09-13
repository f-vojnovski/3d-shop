import { useEffect } from 'react';
import { useThree } from '@react-three/fiber';
import { PMREMGenerator } from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

// glTF renders near-black without IBL under r155+ physical lighting.
// RoomEnvironment is generated in code, so no network fetch and reproducible.
const StudioEnvironment = ({ intensity = 1 }) => {
  const { gl, scene } = useThree();

  useEffect(() => {
    const pmrem = new PMREMGenerator(gl);
    // Named so it can be disposed: one room's geometry per canvas otherwise
    // stays put.
    const room = new RoomEnvironment();
    const target = pmrem.fromScene(room, 0.04);

    // eslint-disable-next-line react-hooks/immutability -- three.js scene state is set by mutation
    scene.environment = target.texture;
    scene.environmentIntensity = intensity;

    return () => {
      scene.environment = null;
      target.dispose();
      pmrem.dispose();
      room.dispose();
    };
  }, [gl, scene, intensity]);

  return null;
};

export default StudioEnvironment;
