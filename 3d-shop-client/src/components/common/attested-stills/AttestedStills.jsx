import { useState } from 'react';
import LoadingSpinner from '../spinner/LoadingSpinner';
import styles from './AttestedStills.module.css';

const IN_PROGRESS = ['queued', 'rendering'];
const SELLER = 'seller';

const AttestedStills = ({ product }) => {
  const [tab, setTab] = useState(null);
  const [selected, setSelected] = useState(0);

  const previews = product.previews ?? [];
  const sellerImages = product.seller_images ?? [];
  const isOwner = product.product_status === 'owner';

  const tabs = [
    ...previews.map((preview) => ({ key: preview.format, label: `.${preview.format}`, preview })),
    ...(sellerImages.length > 0
      ? [{ key: SELLER, label: 'From the seller', preview: null }]
      : []),
  ];

  const shown = tabs.find((one) => one.key === tab)
    ?? tabs.find((one) => one.preview?.images.length > 0)
    ?? tabs[0];

  const pick = (next) => {
    setTab(next);
    setSelected(0);
  };

  const switcher = tabs.length > 1 && (
    <div className={styles.formats} role="tablist" aria-label="Images">
      {tabs.map((one) => (
        <button
          key={one.key}
          type="button"
          role="tab"
          aria-selected={one.key === shown.key}
          className={one.key === shown.key ? styles.formatOn : styles.format}
          onClick={() => pick(one.key)}
        >
          {one.label}
        </button>
      ))}
    </div>
  );

  if (shown === undefined) {
    return (
      <div className={styles.notice}>
        <LoadingSpinner />
        <span>Our server is rendering previews from this model.</span>
      </div>
    );
  }

  const fromSeller = shown.key === SELLER;
  const images = fromSeller ? sellerImages : shown.preview.images;

  if (!fromSeller && IN_PROGRESS.includes(shown.preview.status)) {
    return (
      <div className={styles.notice}>
        <LoadingSpinner />
        <span>Our server is rendering previews from the .{shown.preview.format} file.</span>
        {switcher}
      </div>
    );
  }

  if (images.length === 0) {
    const nothingRequested = shown.preview.status === 'none';

    return (
      <div className={styles.notice}>
        <span>
          {nothingRequested
            ? `The .${shown.preview.format} file has no camera angles, so there is nothing to render.`
            : `Previews could not be rendered from the .${shown.preview.format} file.`}
        </span>
        {isOwner && (
          <span className={styles.hint}>
            {nothingRequested
              ? 'Capture at least one camera angle to get server-rendered previews.'
              : shown.preview.error}
          </span>
        )}
        {switcher}
      </div>
    );
  }

  const image = images[Math.min(selected, images.length - 1)];
  const alt = fromSeller
    ? `${product.name}, image ${image.sort + 1} from the seller`
    : `${product.name}, .${shown.preview.format} view ${image.sort + 1}`;

  return (
    <div className={styles.stills}>
      <div className={styles.stage}>
        <img src={image.url} alt={alt} />
      </div>

      {switcher}

      {images.length > 1 && (
        <div className={styles.strip}>
          {images.map((option, index) => (
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

      <div className={fromSeller ? styles.badgeSeller : styles.badge}>
        {fromSeller
          ? 'Supplied by the seller. Not rendered from the model on sale.'
          : `Rendered by our server from the .${shown.preview.format} file on sale.`}
      </div>
    </div>
  );
};

export default AttestedStills;
