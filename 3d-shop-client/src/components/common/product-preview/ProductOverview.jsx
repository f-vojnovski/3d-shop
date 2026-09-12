import { useState } from 'react';
import { MdChevronLeft, MdChevronRight } from 'react-icons/md';
import { Link } from 'react-router-dom';
import { formatPrice } from '../../../service/util/formatPrice';
import styles from './ProductOverview.module.css';

const ProductOverview = ({ id, name, priceCents, images = [], status }) => {
  const [shown, setShown] = useState(0);

  // The whole picture is a link to the product, so a paging button inside it
  // would be a link inside a link. They sit above the hit area instead, and
  // have to say they are not a navigation.
  const step = (by) => (event) => {
    event.preventDefault();
    setShown((current) => (current + by + images.length) % images.length);
  };

  return (
    <div className={styles.card}>
      <div className={styles.thumb}>
        {images.length > 0 ? (
          <img
            src={images[shown]}
            alt={`${name} preview ${shown + 1}`}
            loading="lazy"
            decoding="async"
          />
        ) : (
          <div className={styles.empty}>No preview</div>
        )}

        {status && status !== 'live' && (
          <span className={styles.badge}>
            {status === 'draft' ? 'Not published' : 'Off sale'}
          </span>
        )}

        <Link to={`/product/${id}`} className={styles.hit} aria-label={name} />

        {images.length > 1 && (
          <>
            <button
              type="button"
              className={styles.prev}
              onClick={step(-1)}
              aria-label="Previous image"
            >
              <MdChevronLeft aria-hidden="true" />
            </button>
            <button
              type="button"
              className={styles.next}
              onClick={step(1)}
              aria-label="Next image"
            >
              <MdChevronRight aria-hidden="true" />
            </button>

            <div className={styles.dots}>
              {images.map((url, index) => (
                <span key={url} className={index === shown ? styles.dotOn : styles.dot} />
              ))}
            </div>
          </>
        )}
      </div>

      <Link to={`/product/${id}`} className={styles.body}>
        <div className={styles.name}>{name}</div>
        <div className={styles.price}>${formatPrice(priceCents)}</div>
      </Link>
    </div>
  );
};

export default ProductOverview;
