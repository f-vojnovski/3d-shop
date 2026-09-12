import { useState } from 'react';
import Lightbox from '../lightbox/Lightbox';
import styles from './ReleaseStills.module.css';

const asDate = (value) =>
  new Date(value).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * A superseded release on the main stage: its own renders, at the size the
 * current file gets. The old mesh itself is not served, so these images and the
 * measurements beside them are the whole of what that release can still show.
 */
const ReleaseStills = ({ release, format, productName }) => {
  const [selected, setSelected] = useState(0);
  const [zoomed, setZoomed] = useState(false);

  const images = release.images ?? [];
  const replaced = asDate(release.replaced_at);

  const banner = (
    <div className={styles.banner}>
      An older release of the .{format} file, replaced on {replaced}. This is not the file
      on sale.
    </div>
  );

  if (images.length === 0) {
    return (
      <div className={styles.release}>
        {banner}
        <div className={styles.none}>
          <span>Nothing was rendered from this release.</span>
          {release.note && <span className={styles.note}>“{release.note}”</span>}
        </div>
      </div>
    );
  }

  const image = images[Math.min(selected, images.length - 1)];
  const alt = `${productName}, .${format} view ${image.sort + 1}, the release replaced on ${replaced}`;

  return (
    <div className={styles.release}>
      {banner}

      <button
        type="button"
        className={styles.stage}
        onClick={() => setZoomed(true)}
        aria-label={`${alt}. Open full size`}
      >
        <img src={image.url} alt={alt} />
      </button>

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
              <img src={option.url} alt="" loading="lazy" decoding="async" />
            </button>
          ))}
        </div>
      )}

      <div className={styles.footer}>
        {release.note && <span className={styles.note}>“{release.note}”</span>}
        {image.attestation_url && (
          <a
            className={styles.record}
            href={image.attestation_url}
            target="_blank"
            rel="noreferrer"
          >
            What this image was rendered from
          </a>
        )}
      </div>

      {zoomed && (
        <Lightbox
          src={image.url}
          alt={alt}
          caption={`Replaced on ${replaced}`}
          onClose={() => setZoomed(false)}
        />
      )}
    </div>
  );
};

export default ReleaseStills;
