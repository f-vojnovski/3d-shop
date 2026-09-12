import { Suspense, useEffect, useMemo, useState } from 'react';
import { Canvas, useLoader } from '@react-three/fiber';
import { Box3, Group, Mesh, MeshStandardMaterial, Vector3 } from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import useFitToView from './useFitToView';
import SceneLighting from './SceneLighting';
import { OrbitControls } from '@react-three/drei';
import CameraSync from './cameraLink';
import { simplifyObject, trianglesIn } from './simplify';
import { shrinkTextures } from './shrinkTextures';

const LOADERS = { gltf: GLTFLoader, obj: OBJLoader, stl: STLLoader };

// Simplifying a large model takes a moment, and this panel is opened, closed and
// swapped often. Keyed by what it was made from, so a ratio already seen comes
// back instantly instead of being recomputed.
const made = new Map();

/** Every loader answers with something different; the simplifier wants a scene. */
const asScene = (loaded) => {
  if (loaded?.scene) {
    return loaded.scene;
  }

  if (loaded?.isBufferGeometry) {
    const group = new Group();
    group.add(new Mesh(loaded, new MeshStandardMaterial()));

    return group;
  }

  return loaded;
};

const Proxy = ({ format, uri, keep, obscured, onCounts }) => {
  const loaded = useLoader(LOADERS[format] ?? GLTFLoader, uri);
  const original = useMemo(() => asScene(loaded), [loaded]);
  const [proxy, setProxy] = useState(null);

  const size = useMemo(
    () => new Box3().setFromObject(original).getSize(new Vector3()).toArray(),
    [original]
  );

  useEffect(() => {
    if (obscured) {
      return undefined;
    }

    let live = true;
    const key = uri + '|' + keep;

    // Always through the promise, cache hit or not: resolving in a microtask
    // keeps this out of the effect body, where setting state cascades renders.
    const build = async () => {
      const ready = made.get(key);

      if (ready) {
        return ready;
      }

      // Cloning shares geometry, and the simplifier returns new geometry
      // rather than editing in place, so the model being framed is untouched.
      const copy = original.clone(true);
      const before = trianglesIn(copy);

      await simplifyObject(copy, keep);

      // The same shrink the stored copy gets, so the seller approves the
      // textures a buyer is shown rather than the full-size ones.
      shrinkTextures(copy);

      const entry = { scene: copy, counts: { before, after: trianglesIn(copy) } };
      made.set(key, entry);

      return entry;
    };

    build().then((entry) => {
      if (!live) {
        return;
      }

      setProxy(entry.scene);
      onCounts(entry.counts);
    });
    return () => {
      live = false;
    };
  }, [original, uri, keep, obscured, onCounts]);

  // Fitted to the full model, not to itself: simplifying can nibble the
  // silhouette, and a proxy that re-fits would sit at a different size from the
  // model it is meant to be compared against.
  const { scale, center } = useFitToView(original);

  // Obscured is the older answer: buyers get the bounding box and nothing of
  // the shape. Drawn here too, because this panel is what they will see.
  if (obscured) {
    return (
      <group scale={scale}>
        <group position={center}>
          <mesh>
            <boxGeometry args={size} />
            <meshStandardMaterial color="#8a949c" wireframe />
          </mesh>
        </group>
      </group>
    );
  }

  if (!proxy) {
    return null;
  }

  return (
    <group scale={scale}>
      <group position={center}>
        <primitive object={proxy} />
      </group>
    </group>
  );
};

const ProxyPreview = ({ format, uri, keep, obscured, onCounts, sync }) => (
  <Canvas>
    <SceneLighting format={format} />

    <Suspense fallback={null}>
      <Proxy
        format={format}
        uri={uri}
        keep={keep}
        obscured={obscured}
        onCounts={onCounts}
      />
    </Suspense>

    <OrbitControls makeDefault />
    {sync && <CameraSync {...sync} />}
  </Canvas>
);

export default ProxyPreview;
