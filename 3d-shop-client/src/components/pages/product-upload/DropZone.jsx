import { useRef, useState } from 'react';
import { acceptAttribute } from './formats';
import styles from './ProductUpload.module.css';

const DropZone = ({ onFiles, compact }) => {
  const [over, setOver] = useState(false);
  const input = useRef(null);

  const take = (files) => {
    const chosen = Array.from(files ?? []);

    if (chosen.length > 0) {
      onFiles(chosen);
    }

    if (input.current) {
      input.current.value = '';
    }
  };

  return (
    <button
      type="button"
      className={`${compact ? styles.dropCompact : styles.drop} ${over ? styles.dropOver : ''}`}
      onClick={() => input.current?.click()}
      onDragOver={(event) => {
        event.preventDefault();
        setOver(true);
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        event.preventDefault();
        setOver(false);
        take(event.dataTransfer.files);
      }}
    >
      <span className={styles.plus} aria-hidden="true">
        +
      </span>
      {!compact && <span className={styles.dropHint}>Drop a model, or click to choose</span>}

      <input
        ref={input}
        type="file"
        multiple
        accept={acceptAttribute}
        className={styles.hiddenInput}
        onChange={(event) => take(event.target.files)}
      />
    </button>
  );
};

export default DropZone;
