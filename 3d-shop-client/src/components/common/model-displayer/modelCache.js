import { useLoader } from '@react-three/fiber';
import { useGLTF } from '@react-three/drei';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';

// Every loader that any viewer here reaches for. drei and fiber keep separate
// caches even for the same loader, and both are keyed on the url and never
// evict, so a model left in either stays parsed for the life of the tab.
const LOADERS = [GLTFLoader, OBJLoader, STLLoader];

export const clearModelCache = (url) => {
  if (!url) {
    return;
  }

  try {
    useGLTF.clear(url);
  } catch {
    // Only whichever cache holds this url has anything to drop.
  }

  for (const loader of LOADERS) {
    try {
      useLoader.clear(loader, url);
    } catch {
      // As above.
    }
  }
};
