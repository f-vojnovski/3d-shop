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

// Keyed by what it was made from, so a ratio already seen comes back instantly.
// Bounded, because the slider has a hundred stops and each one builds a scene
// of its own. Oldest out first, and what it built goes with it.
const made = new Map();

const KEEP = 4;

/** What a mesh already held, so a rebuild can tell its own work from the model's. */
const resourcesOf = (root) => {
  const seen = new Set();

  root.traverse((node) => {
    if (!node.isMesh) {
      return;
    }

    seen.add(node.geometry);

    for (const material of [node.material].flat()) {
      if (!material) {
        continue;
      }

      seen.add(material);

      for (const value of Object.values(material)) {
        if (value?.isTexture) {
          seen.add(value);
        }
      }
    }
  });

  return seen;
};

/**
 * Frees what building this copy created, and only that. A copy asked to keep
 * every triangle shares its geometry with the model it was cloned from, so
 * disposing by traversal alone would empty the full-size view beside it.
 */
const release = (root, shared) => {
  for (const thing of resourcesOf(root)) {
    if (!shared.has(thing)) {
      thing.dispose?.();
    }
  }
};

const forget = (key) => {
  const entry = made.get(key);

  if (entry) {
    release(entry.scene, entry.shared);
    made.delete(key);
  }
};

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

const Proxy = ({ format, uri, keep, method, obscured, onCounts }) => {
  const loaded = useLoader(LOADERS[format] ?? GLTFLoader, uri);
  const original = useMemo(() => asScene(loaded), [loaded]);
  const [proxy, setProxy] = useState(null);

  const size = useMemo(
    () => new Box3().setFromObject(original).getSize(new Vector3()).toArray(),
    [original]
  );

  // Copies of a model no longer on screen are worth nothing.
  useEffect(() => {
    for (const key of [...made.keys()]) {
      if (!key.startsWith(uri + '|')) {
        forget(key);
      }
    }
  }, [uri]);

  useEffect(() => {
    if (obscured) {
      return undefined;
    }

    let live = true;
    const key = uri + '|' + keep + '|' + method;

    // Always through the promise, cache hit or not: resolving in a microtask
    // keeps this out of the effect body, where setting state cascades renders.
    const build = async () => {
      const ready = made.get(key);

      if (ready) {
        return ready;
      }

      // Cloning shares geometry, and the simplifier returns new geometry
      // rather than editing in place, so the model being framed is untouched.
      const shared = resourcesOf(original);
      const copy = original.clone(true);
      const before = trianglesIn(copy);

      await simplifyObject(copy, keep, method);

      // The same shrink the stored copy gets, so the seller approves the
      // textures a buyer is shown rather than the full-size ones.
      shrinkTextures(copy);

      const entry = { scene: copy, shared, counts: { before, after: trianglesIn(copy) } };
      made.set(key, entry);

      while (made.size > KEEP) {
        forget(made.keys().next().value);
      }

      return entry;
    };

    build().then((entry) => {
      if (!live) {
        return;
      }

      setProxy(entry.scene);
      onCounts(entry.counts);
    }, (error) => {
      // A rejection here has nothing else listening, and the panel would sit on
      // "working it out" forever with the reason reaching nobody.
      console.error('The buyer preview could not be built.', error);

      if (live) {
        onCounts({ failed: true });
      }
    });
    return () => {
      live = false;
    };
  }, [original, uri, keep, method, obscured, onCounts]);

  // Fitted to the full model, not to itself: simplifying nibbles the
  // silhouette, and a proxy that re-fits would not line up with the model it
  // is being compared against.
  const { scale, center } = useFitToView(original);

  // Obscured means buyers get the bounding box and nothing of the shape.
  // Drawn here too, because this panel is what they will see.
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

/**
 * `paused` stops the draw loop rather than unmounting: hiding this panel keeps
 * its WebGL context alive on purpose, and a context that is alive but invisible
 * was still drawing sixty times a second.
 */
const ProxyPreview = ({ format, uri, keep, method, obscured, paused, onCounts, sync }) => (
  <Canvas flat frameloop={paused ? 'never' : 'always'}>
    <SceneLighting format={format} />

    <Suspense fallback={null}>
      <Proxy
        format={format}
        uri={uri}
        keep={keep}
        method={method}
        obscured={obscured}
        onCounts={onCounts}
      />
    </Suspense>

    <OrbitControls makeDefault />
    {sync && <CameraSync {...sync} />}
  </Canvas>
);

export default ProxyPreview;
