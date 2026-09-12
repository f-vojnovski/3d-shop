import { MdBlurOn, MdClose, MdSwapHoriz, MdVisibility, MdVisibilityOff } from 'react-icons/md';
import { CAREFUL, PARTS } from '../../common/model-displayer/simplify';
import styles from './ProductUpload.module.css';

const count = (value) => value.toLocaleString('en-US');

// Three answers to one question, so they belong in one control. Handing over
// the real model and cutting a copy down are alternatives, not settings that
// stack.
const MODES = [
  { key: 'model', Icon: MdVisibility, label: 'Buyers spin the real model' },
  { key: 'decimated', Icon: MdBlurOn, label: 'Buyers aim at a cut-down copy' },
  { key: 'box', Icon: MdVisibilityOff, label: 'Buyers see only an outline box' },
];

// A model built from many separate parts has almost no edges left to collapse,
// so the careful cut can leave the slider looking broken. Dropping whole small
// parts reaches the number instead, and loses the smallest details doing it.
const METHODS = [
  { key: CAREFUL, label: 'Keep every piece' },
  { key: PARTS, label: 'Drop small pieces' },
];

const ProxyPanel = ({
  mode, ratio, method, counts, shown, swapped, onMode, onRatio, onMethod, onSwap, onShown,
}) => {
  if (!shown) {
    return (
      <button type="button" className={styles.insetShow} onClick={() => onShown(true)}>
        <MdVisibility aria-hidden="true" /> Buyer preview
      </button>
    );
  }

  const note =
    mode === 'decimated'
      ? counts?.failed
        ? 'Decimation: this model could not be cut down.'
        : counts
          ? `Decimation: ${count(counts.before)} tris => ${count(counts.after)} tris`
          : 'Decimation: working it out…'
      : mode === 'model'
        ? 'Buyers get the model itself to spin.'
        : 'Buyers get a box the size of your model, and nothing of its shape.';

  return (
    <div className={styles.inset}>
      {/* Holds the space the small canvas is laid over. Neither canvas lives in
          here: moving one between parents would rebuild its WebGL context. */}
      <div className={styles.insetPicture} />

      <div className={styles.insetHead}>
        <span>{swapped ? 'Your model' : 'Buyer preview'}</span>
        <span className={styles.insetTools}>
          <button
            type="button"
            title="Swap which one is large"
            aria-label="Swap which one is large"
            onClick={onSwap}
          >
            <MdSwapHoriz aria-hidden="true" />
          </button>
          <button
            type="button"
            title="Hide the buyer preview"
            aria-label="Hide the buyer preview"
            onClick={() => onShown(false)}
          >
            <MdClose aria-hidden="true" />
          </button>
        </span>
      </div>

      <div className={styles.insetModes}>
        {MODES.map(({ key, Icon, label }) => (
          <button
            key={key}
            type="button"
            className={key === mode ? styles.modeOn : styles.mode}
            aria-pressed={key === mode}
            title={label}
            aria-label={label}
            onClick={() => onMode(key)}
          >
            <Icon aria-hidden="true" />
          </button>
        ))}
      </div>

      {mode === 'decimated' && (
        <div className={styles.methods}>
          {METHODS.map(({ key, label }) => (
            <button
              key={key}
              type="button"
              className={key === method ? styles.methodOn : styles.method}
              aria-pressed={key === method}
              onClick={() => onMethod(key)}
            >
              {label}
            </button>
          ))}
        </div>
      )}

      {mode === 'decimated' && (
        <input
          className={styles.proxySlider}
          type="range"
          min="0"
          max="100"
          step="1"
          value={Math.round(ratio * 100)}
          aria-label="How much of the model buyers can aim at"
          onChange={(event) => onRatio(Number(event.target.value) / 100)}
        />
      )}

      <p className={styles.insetNote}>{note}</p>
    </div>
  );
};

export default ProxyPanel;
