import styles from './ModelFacts.module.css';

const count = (value) => value.toLocaleString('en-US');

// A broken .mtl can name every texture in the pack; 63 lines help nobody.
const MISSING_SHOWN = 6;

// glTF is the only format here that fixes a unit — its spec puts every linear
// distance in metres. An .obj or .stl carries none, and an .fbx is measured
// after conversion, by which point its header scale is gone. Printing bare
// numbers let a 12-unit room read as a 12-metre barn.
const size = (bounds, format) =>
  bounds.size.map((value) => value.toFixed(2)).join(' × ')
  + (format === 'gltf' ? ' m' : ' units');

/**
 * What a rigged model is really made of. "249 bones" sounds like a lot of work
 * until you learn that 35 of them touch the mesh and the rest are the controls
 * the artist left in, so both numbers go on the same line or neither does.
 */
const rigRows = (facts) => {
  const rig = facts.rig;

  if (!rig) {
    return [
      facts.rigged ? ['Rigged', 'Yes'] : null,
      facts.animated ? ['Animated', 'Yes'] : null,
    ];
  }

  const clips = rig.clips?.length ?? 0;

  return [
    rig.bones
      ? ['Rigged', rig.bones_used != null && rig.bones_used !== rig.bones
        ? `${count(rig.bones)} bones, ${count(rig.bones_used)} do the work`
        : `${count(rig.bones)} bones`]
      : null,
    rig.naming ? ['Bone names', rig.naming] : null,
    rig.max_influences ? ['Bones per point', `up to ${rig.max_influences}`] : null,
    rig.morph_targets ? ['Face shapes', count(rig.morph_targets)] : null,
    clips > 0 ? ['Animated', clips === 1 ? '1 clip' : `${clips} clips`] : null,
    // Only worth a line when it is a fault: these are the points that stay put
    // while the rest of the model moves.
    rig.unweighted_vertices
      ? ['Loose points', `${count(rig.unweighted_vertices)} attached to no bone`]
      : null,
  ];
};

const largestTexture = (textures) =>
  textures.reduce((widest, one) => Math.max(widest, one.width, one.height), 0);

// A file the server could not read leaves every field null, and "UVs: No"
// would be a claim rather than a measurement.
const measured = (facts) =>
  Boolean(facts) && (facts.faces != null || facts.vertices != null || Boolean(facts.bounds));

const ModelFacts = ({ facts, format, agreement, missing, unusedImages, label }) => {
  if (!measured(facts)) {
    return null;
  }

  const disagrees = agreement && agreement.agrees === false;

  const rows = [
    facts.faces ? ['Faces', `${count(facts.faces)}${facts.topology === 'unknown' ? '' : ` (${facts.topology})`}`] : null,
    facts.vertices ? ['Vertices', count(facts.vertices)] : null,
    facts.bounds ? ['Bounding box', size(facts.bounds, format)] : null,
    ['UVs', facts.uvs ? 'Yes' : 'No'],
    ['Normals', facts.normals ? 'Yes' : 'No'],
    facts.materials ? ['Materials', count(facts.materials)] : null,
    facts.textures.length > 0
      ? ['Textures', `${facts.textures.length}, up to ${largestTexture(facts.textures)}px`]
      : null,
    ...rigRows(facts),
  ].filter(Boolean);

  return (
    <div className={styles.facts}>
      <p className={styles.label}>{label ?? 'Measured from the .' + format + ' file'}</p>
      <dl className={styles.grid}>
        {rows.map(([name, value]) => (
          <div key={name} className={styles.row}>
            <dt>{name}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>

      {missing?.length > 0 && (
        <div className={styles.mismatch}>
          <p>
            {missing.length === 1
              ? 'The model asks for a texture the download does not contain:'
              : `The model asks for ${missing.length} textures the download does not contain:`}
          </p>
          <ul>
            {missing.slice(0, MISSING_SHOWN).map((path) => (
              <li key={path}>{path}</li>
            ))}
            {missing.length > MISSING_SHOWN && (
              <li>and {count(missing.length - MISSING_SHOWN)} more</li>
            )}
          </ul>

          {unusedImages?.length > 0 && (
            <>
              <p>
                {unusedImages.length === 1
                  ? 'The archive does contain one image nothing references:'
                  : `The archive does contain ${unusedImages.length} images nothing references:`}
              </p>
              <ul>
                {unusedImages.slice(0, MISSING_SHOWN).map((path) => (
                  <li key={path}>{path}</li>
                ))}
                {unusedImages.length > MISSING_SHOWN && (
                  <li>and {count(unusedImages.length - MISSING_SHOWN)} more</li>
                )}
              </ul>
            </>
          )}
        </div>
      )}

      {disagrees && (
        <div className={styles.mismatch}>
          <p>The formats in this product are not the same model.</p>
          <ul>
            {agreement.differences.map((difference) => (
              <li key={difference}>{difference}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
};

export default ModelFacts;
