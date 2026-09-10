import styles from './ModelFacts.module.css';

const count = (value) => value.toLocaleString('en-US');

const size = (bounds) =>
  bounds.size.map((value) => value.toFixed(2)).join(' × ');

const largestTexture = (textures) =>
  textures.reduce((widest, one) => Math.max(widest, one.width, one.height), 0);

const ModelFacts = ({ facts, format }) => {
  if (!facts) {
    return null;
  }

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
    </div>
  );
};

export default ModelFacts;
