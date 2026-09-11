import { formatBytes } from '../../../service/util/formatBytes';
import styles from './BundleContents.module.css';

const BundleContents = ({ bundle, format }) => {
  if (!bundle) {
    return null;
  }

  const { entry, digest, bytes, files } = bundle;

  return (
    <details className={styles.contents}>
      <summary>
        <span className={styles.title}>
          {files.length} file{files.length === 1 ? '' : 's'} in the .{format} download
        </span>
        <span className={styles.total}>{formatBytes(bytes)} zipped</span>
      </summary>

      <ul className={styles.files}>
        {files.map((file) => (
          <li key={file.path} className={file.path === entry ? styles.model : undefined}>
            <span className={styles.path} title={file.sha256}>
              {file.path}
            </span>
            <span className={styles.bytes}>{formatBytes(file.bytes)}</span>
          </li>
        ))}
      </ul>

      {/* Not the model's checksum: the stills were attested against the whole
          set, so changing any one file here changes what the images describe. */}
      <p className={styles.digest}>
        Contents fingerprint <code>{digest.slice(0, 16)}…</code>
      </p>
    </details>
  );
};

export default BundleContents;
