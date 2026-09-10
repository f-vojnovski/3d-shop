import { useEffect } from 'react';
import { useThree } from '@react-three/fiber';

const round = (n) => Math.round(n * 1000) / 1000;
const ROLL_WIDTH = 320;

// Rendering and reading in the same tick avoids preserveDrawingBuffer, which
// would cost every frame for something that happens on a button press.
const snapshotOf = (gl, scene, camera) => {
  gl.render(scene, camera);

  const source = gl.domElement;
  const scaled = document.createElement('canvas');
  scaled.width = ROLL_WIDTH;
  scaled.height = Math.round((source.height / source.width) * ROLL_WIDTH);
  const context = scaled.getContext('2d');
  context.fillStyle = '#171b1e';
  context.fillRect(0, 0, scaled.width, scaled.height);
  context.drawImage(source, 0, 0, scaled.width, scaled.height);

  return scaled.toDataURL('image/jpeg', 0.72);
};

// Only the camera numbers are ever uploaded; the image stays in the browser.
const CameraProbe = ({ probeRef }) => {
  const { camera, controls, gl, scene } = useThree();

  useEffect(() => {
    if (!probeRef) {
      return undefined;
    }

    probeRef.current = () => ({
      camera: {
        position: camera.position.toArray().map(round),
        target: (controls?.target?.toArray() ?? [0, 0, 0]).map(round),
        up: camera.up.toArray().map(round),
        fov: round(camera.fov),
      },
      snapshot: snapshotOf(gl, scene, camera),
    });

    return () => {
      probeRef.current = null;
    };
  }, [camera, controls, gl, scene, probeRef]);

  return null;
};

export default CameraProbe;
