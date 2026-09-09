import { useMemo } from 'react';
import { Box3, Vector3 } from 'three';

// Models arrive at any scale, so measure and refit. The default camera (z=5,
// 75deg fov) sees ~7.7 units of height; 4.5 fills two thirds, leaving orbit room.
const TARGET_SIZE = 4.5;

export default function useFitToView(object) {
  return useMemo(() => {
    if (!object) {
      return { scale: 1, center: [0, 0, 0] };
    }

    const box = new Box3().setFromObject(object);

    if (box.isEmpty()) {
      return { scale: 1, center: [0, 0, 0] };
    }

    const size = box.getSize(new Vector3());
    const center = box.getCenter(new Vector3());
    const largestSide = Math.max(size.x, size.y, size.z);

    return {
      scale: largestSide > 0 ? TARGET_SIZE / largestSide : 1,
      center: [-center.x, -center.y, -center.z],
    };
  }, [object]);
}
