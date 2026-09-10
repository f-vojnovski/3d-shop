import { useState } from 'react';
import LoadingSpinner from '../spinner/LoadingSpinner';
import styles from './AttestedStills.module.css';

const IN_PROGRESS = ['queued', 'rendering'];

const AttestedStills = ({ product }) => {
  const [selected, setSelected] = useState(0);

  const stills = product.preview_images ?? [];
  const isOwner = product.product_status === 'owner';

  if (IN_PROGRESS.includes(product.preview_status)) {
    return (
      <div className={styles.notice}>
        <LoadingSpinner />
        <span>Our server is rendering previews from this model.</span>
      </div>
    );
  }

  if (stills.length === 0) {
    // 'none' means nothing was ever asked for; 'failed' means we tried.
    const nothingRequested = product.preview_status === 'none';

    return (
      <div className={styles.notice}>
        <span>
          {nothingRequested
            ? 'This product has no camera angles, so there is nothing to render.'
            : 'Previews could not be rendered for this product.'}
        </span>
        {isOwner && (
          <span className={styles.hint}>
            {nothingRequested
              ? 'Capture at least one camera angle to get server-rendered previews.'
              : product.preview_error}
          </span>
        )}
      </div>
    );
  }

  const still = stills[Math.min(selected, stills.length - 1)];

  return (
    <div className={styles.stills}>
      <div className={styles.stage}>
        <img src={still.url} alt={`${product.name}, view ${still.sort + 1}`} />
      </div>

      {stills.length > 1 && (
        <div className={styles.strip}>
          {stills.map((option, index) => (
            <button
              key={option.url}
              type="button"
              className={index === selected ? styles.selected : undefined}
              aria-label={`View ${index + 1}`}
              aria-current={index === selected}
              onClick={() => setSelected(index)}
            >
              <img src={option.url} alt="" />
            </button>
          ))}
        </div>
      )}

      <div className={styles.badge}>Rendered by our server from the model on sale.</div>
    </div>
  );
};

export default AttestedStills;
