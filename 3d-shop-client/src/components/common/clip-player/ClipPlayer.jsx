import { useMemo, useState } from 'react';
import styles from './ClipPlayer.module.css';

const PAINTS = [
  { pass: 'shaded', label: 'Moving' },
  { pass: 'influence', label: 'Which bone moves what' },
  { pass: 'bones', label: 'Skeleton' },
];

const NOTES = {
  influence: 'Each part is coloured by the bone that pulls it. Pink means no bone pulls it at all.',
  bones: 'The skeleton, drawn through the body.',
};

const nameOf = (clip, at) => clip.name || `Clip ${at + 1}`;

const seconds = (value) =>
  typeof value === 'number' ? `${value.toFixed(value < 10 ? 2 : 0)}s` : null;

/**
 * The model's animations, as moving pictures. Pictures on purpose: a walk cycle
 * lifts onto somebody else's character, so it never leaves the server.
 */
const ClipPlayer = ({ clips }) => {
  const [at, setAt] = useState(0);
  const [paint, setPaint] = useState('shaded');

  const clip = clips?.[Math.min(at, (clips?.length ?? 1) - 1)];

  const painted = useMemo(
    () => PAINTS.filter(({ pass }) => clip?.passes?.some((one) => one.pass === pass)),
    [clip]
  );

  if (!clip || painted.length === 0) {
    return null;
  }

  const showing = clip.passes.find((one) => one.pass === paint) ?? clip.passes[0];

  return (
    <div className={styles.clips}>
      <p className={styles.label}>Animations</p>

      {clips.length > 1 && (
        <div className={styles.row}>
          {clips.map((one, index) => (
            <button
              key={one.index}
              type="button"
              className={index === at ? styles.pickOn : styles.pick}
              aria-pressed={index === at}
              onClick={() => setAt(index)}
            >
              {nameOf(one, index)}
            </button>
          ))}
        </div>
      )}

      <img
        className={styles.picture}
        src={showing.url}
        alt={`${nameOf(clip, at)}, ${showing.pass}`}
        width={512}
        height={512}
      />

      <div className={styles.row}>
        {painted.map(({ pass, label }) => (
          <button
            key={pass}
            type="button"
            className={pass === showing.pass ? styles.pickOn : styles.pick}
            aria-pressed={pass === showing.pass}
            onClick={() => setPaint(pass)}
          >
            {label}
          </button>
        ))}
      </div>

      <p className={styles.note}>
        {NOTES[showing.pass]
          ?? [nameOf(clip, at), seconds(clip.seconds), clip.frames ? `${clip.frames} frames` : null]
            .filter(Boolean)
            .join(' · ')}
      </p>
    </div>
  );
};

export default ClipPlayer;
