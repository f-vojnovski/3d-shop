import { useEffect } from 'react';
import { useThree } from '@react-three/fiber';
import { PMREMGenerator } from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

// PBR materials need image-based lighting to read correctly; without it glTF
// assets render near-black under physically correct lights (three r155+).
// RoomEnvironment is generated in code rather than fetched, so this stays
// offline-safe and reproducible, which the attested render pipeline relies on.
const StudioEnvironment = ({ intensity = 1 }) => {
  const { gl, scene } = useThree();

  useEffect(() => {
    const pmrem = new PMREMGenerator(gl);
    const target = pmrem.fromScene(new RoomEnvironment(), 0.04);

    scene.environment = target.texture;
    scene.environmentIntensity = intensity;

    return () => {
      scene.environment = null;
      target.dispose();
      pmrem.dispose();
    };
  }, [gl, scene, intensity]);

  return null;
};

export default StudioEnvironment;
