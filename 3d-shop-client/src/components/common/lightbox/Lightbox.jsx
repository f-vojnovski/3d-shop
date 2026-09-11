import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { MdClose } from 'react-icons/md';
import styles from './Lightbox.module.css';

/**
 * One image, full screen, over a blurred page. Escape closes it and focus goes
 * back to whatever opened it, the same way the dropdown menu behaves.
 */
const Lightbox = ({ src, alt, caption, onClose }) => {
  const closeRef = useRef(null);
  const returnTo = useRef(null);

  useEffect(() => {
    returnTo.current = document.activeElement;
    closeRef.current?.focus();

    const onKey = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
      }
    };

    document.addEventListener('keydown', onKey);

    // The page behind must not scroll under a full-screen image.
    const scroll = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = scroll;

      if (returnTo.current instanceof HTMLElement) {
        returnTo.current.focus();
      }
    };
  }, [onClose]);

  // Portalled to the body: an ancestor with a transform or filter becomes the
  // containing block for position:fixed, which left the header drawn on top.
  return createPortal(
    <div
      className={styles.backdrop}
      role="dialog"
      aria-modal="true"
      aria-label={alt}
      onClick={onClose}
    >
      <button ref={closeRef} type="button" className={styles.close} onClick={onClose}>
        <MdClose aria-hidden="true" />
        <span className="visually-hidden">Close</span>
      </button>

      {/* Clicking the image itself should not dismiss what you came to look at. */}
      <figure className={styles.frame} onClick={(event) => event.stopPropagation()}>
        <img src={src} alt={alt} />
        {caption && <figcaption>{caption}</figcaption>}
      </figure>
    </div>,
    document.body,
  );
};

export default Lightbox;
