import { useMemo } from 'react';
import { Box3, Vector3 } from 'three';

// The camera sits at a fixed distance, so a model only looks right if it happens
// to be authored at roughly the size the camera expects. Uploaded models are not:
// the sample assets range from 0.4 to 5 units across, which renders one of them as
// a speck and would push a larger one out of frame. Measure the model instead and
// return the scale and offset that centre it and make it fill the view.
// Sized against the default camera, which sits at z=5 with a 75 degree field of
// view and so sees roughly 7.7 units of height at the origin. This fills about
// two thirds of that, leaving room to orbit without clipping.
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
