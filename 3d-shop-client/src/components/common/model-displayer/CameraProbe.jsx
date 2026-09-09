import { useEffect } from 'react';
import { useThree } from '@react-three/fiber';

const round = (n) => Math.round(n * 1000) / 1000;

// Lives inside the Canvas so it can read the live camera, and hands the parent
// a function to call. The seller sends these numbers, never an image.
const CameraProbe = ({ probeRef }) => {
  const { camera, controls } = useThree();

  useEffect(() => {
    if (!probeRef) {
      return;
    }

    probeRef.current = () => ({
      position: camera.position.toArray().map(round),
      target: (controls?.target?.toArray() ?? [0, 0, 0]).map(round),
      up: camera.up.toArray().map(round),
      fov: round(camera.fov),
    });

    return () => {
      probeRef.current = null;
    };
  }, [camera, controls, probeRef]);

  return null;
};

export default CameraProbe;
