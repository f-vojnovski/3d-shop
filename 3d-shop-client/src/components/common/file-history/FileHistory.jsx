import { useState } from 'react';
import styles from './FileHistory.module.css';

const asDate = (value) =>
  new Date(value).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * Picks which release the page shows. The release itself is drawn on the main
 * stage, not here — this is the control for it.
 */
const FileHistory = ({ format, releases, viewing, onView }) => {
  const [picking, setPicking] = useState(false);

  if (!releases || releases.length === 0) {
    return null;
  }

  const showing = typeof viewing === 'number' ? releases[viewing] : null;

  let heading = `Last updated on ${asDate(releases[0].replaced_at)}`;

  if (picking) {
    heading = `Older releases of the .${format} file`;
  } else if (showing) {
    heading = `Showing the release until ${asDate(showing.replaced_at)}`;
  }

  const choose = (index) => {
    onView(index);
    setPicking(false);
  };

  const backToLatest = () => {
    onView(null);
    setPicking(false);
  };

  return (
    <div className={styles.history}>
      <p className={styles.label}>{heading}</p>

      {picking && (
        <ol className={styles.picker}>
          {releases.map((release, index) => (
            <li key={release.replaced_at + release.sha256}>
              <button type="button" className={styles.pick} onClick={() => choose(index)}>
                <span className={styles.when}>Until {asDate(release.replaced_at)}</span>
                {release.facts?.faces && (
                  <span className={styles.faces}>
                    {release.facts.faces.toLocaleString('en-US')} faces
                  </span>
                )}
              </button>
            </li>
          ))}
        </ol>
      )}

      <div className={styles.actions}>
        {!picking && !showing && (
          <button type="button" className={styles.action} onClick={() => setPicking(true)}>
            View older releases
          </button>
        )}

        {(picking || showing) && (
          <button type="button" className={styles.action} onClick={backToLatest}>
            Back to latest
          </button>
        )}

        {showing && !picking && (
          <button type="button" className={styles.action} onClick={() => setPicking(true)}>
            View another release
          </button>
        )}
      </div>
    </div>
  );
};

export default FileHistory;
