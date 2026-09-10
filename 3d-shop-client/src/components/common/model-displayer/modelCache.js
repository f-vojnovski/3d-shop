import { useLoader } from '@react-three/fiber';
import { useGLTF } from '@react-three/drei';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';

// The loader cache is keyed on the url and never evicts on its own, so a
// detached model would otherwise stay parsed in memory for the tab's life.
export const clearModelCache = (url) => {
  try {
    useGLTF.clear(url);
  } catch {
    // Only one of the two loaders holds any given url.
  }

  try {
    useLoader.clear(OBJLoader, url);
  } catch {
    // As above.
  }
};
