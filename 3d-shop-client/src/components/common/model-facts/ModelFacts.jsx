import styles from './ModelFacts.module.css';

const count = (value) => value.toLocaleString('en-US');

const size = (bounds) =>
  bounds.size.map((value) => value.toFixed(2)).join(' × ');

const largestTexture = (textures) =>
  textures.reduce((widest, one) => Math.max(widest, one.width, one.height), 0);

// A file the server could not read leaves every field null, and "UVs: No"
// would be a claim rather than a measurement.
const measured = (facts) =>
  Boolean(facts) && (facts.faces != null || facts.vertices != null || Boolean(facts.bounds));

const ModelFacts = ({ facts, format, agreement }) => {
  if (!measured(facts)) {
    return null;
  }

  const disagrees = agreement && agreement.agrees === false;

  const rows = [
    facts.faces ? ['Faces', `${count(facts.faces)}${facts.topology === 'unknown' ? '' : ` (${facts.topology})`}`] : null,
    facts.vertices ? ['Vertices', count(facts.vertices)] : null,
    facts.bounds ? ['Bounding box', size(facts.bounds)] : null,
    ['UVs', facts.uvs ? 'Yes' : 'No'],
    ['Normals', facts.normals ? 'Yes' : 'No'],
    facts.materials ? ['Materials', count(facts.materials)] : null,
    facts.textures.length > 0
      ? ['Textures', `${facts.textures.length}, up to ${largestTexture(facts.textures)}px`]
      : null,
    facts.rigged ? ['Rigged', 'Yes'] : null,
    facts.animated ? ['Animated', 'Yes'] : null,
  ].filter(Boolean);

  return (
    <div className={styles.facts}>
      <p className={styles.label}>Measured from the .{format} file</p>
      <dl className={styles.grid}>
        {rows.map(([name, value]) => (
          <div key={name} className={styles.row}>
            <dt>{name}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>

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
