import { useFrame } from '@react-three/fiber';

/**
 * Keeps two canvases looking at the same thing from the same place, with either
 * one draggable. The canvas the pointer is over is authoritative; the other
 * follows it.
 *
 * Ownership is decided by the pointer rather than by OrbitControls' start/end
 * events: a wheel zoom fires both of those before the dolly is applied, so the
 * follower used to overwrite the zoom on the very next frame.
 *
 * Position and orbit target travel rather than the camera's transform, because
 * OrbitControls rebuilds the camera from those two on every update — writing a
 * transform straight in would be undone immediately.
 */
const CameraSync = ({ stateRef, id, activeRef }) => {
  useFrame((state) => {
    if (!stateRef) {
      return;
    }

    const { camera } = state;
    const orbit = state.controls;

    // Publishes when nothing is shared yet too, so the first canvas to mount
    // seeds the view instead of both waiting on each other.
    if (activeRef?.current === id || !stateRef.current) {
      stateRef.current = {
        position: camera.position.toArray(),
        target: orbit?.target ? orbit.target.toArray() : [0, 0, 0],
        fov: camera.fov,
      };

      return;
    }

    const shared = stateRef.current;

    camera.position.fromArray(shared.position);

    if (camera.fov !== shared.fov) {
      camera.fov = shared.fov;
      camera.updateProjectionMatrix();
    }

    if (orbit?.target) {
      orbit.target.fromArray(shared.target);
      orbit.update();
    } else {
      camera.lookAt(shared.target[0], shared.target[1], shared.target[2]);
    }
  });

  return null;
};

export default CameraSync;
