import styles from './FileHistory.module.css';

const asDate = (value) =>
  new Date(value).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

const FileHistory = ({ format, replaced }) => {
  if (!replaced || replaced.length === 0) {
    return null;
  }

  return (
    <div className={styles.history}>
      <p className={styles.label}>
        .{format} file {replaced.length === 1 ? 'replaced once' : `replaced ${replaced.length} times`}
      </p>

      <ol className={styles.versions}>
        {replaced.map((version) => (
          <li key={version.replaced_at + version.sha256} className={styles.version}>
            <div className={styles.when}>
              Until {asDate(version.replaced_at)}
              {version.facts?.faces ? ` · ${version.facts.faces.toLocaleString('en-US')} faces` : ''}
            </div>

            {version.note && <div className={styles.note}>“{version.note}”</div>}

            {version.images.length > 0 && (
              <div className={styles.strip}>
                {version.images.map((image) => (
                  <a
                    key={image.id}
                    href={image.attestation_url}
                    target="_blank"
                    rel="noreferrer"
                    title="What this image was rendered from"
                  >
                    <img src={image.url} alt={`Previous .${format} view ${image.sort + 1}`} />
                  </a>
                ))}
              </div>
            )}
          </li>
        ))}
      </ol>
    </div>
  );
};

export default FileHistory;
