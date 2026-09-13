import { useEffect, useState } from 'react';
import LoadingSpinner from '../spinner/LoadingSpinner';
import styles from './AttestedStills.module.css';

const IN_PROGRESS = ['queued', 'rendering'];
const SELLER = 'seller';
const CLIPS = 'clips';

// A clip belongs in the gallery beside the stills: it is a picture of the file
// on sale, drawn the same way, and a buyer looks for it in the same place.
const PAINTS = [
  { pass: 'shaded', label: 'Moving' },
  { pass: 'influence', label: 'Which bone moves what' },
  { pass: 'bones', label: 'Skeleton' },
];

const NOTES = {
  shaded: 'System-rendered from the file on sale. A picture of the motion, not the motion itself.',
  influence: 'Each part is coloured by the bone that pulls it. Pink means no bone pulls it at all.',
  bones: 'The skeleton, drawn through the body.',
};

const nameOf = (clip, at) => clip.name || `Clip ${at + 1}`;

// No browser opens an .fbx, so the server turns it into a .glb first and draws
// that. The badge says so rather than leaving the extra step unsaid.
const rendered = (preview) => (preview.converted_to
  ? `System-rendered from the .${preview.format} file on sale, converted to .${preview.converted_to} first.`
  : `System-rendered from the .${preview.format} file on sale.`);

const shadedFirst = (clip) =>
  clip.passes.find((one) => one.pass === 'shaded') ?? clip.passes[0];

const AttestedStills = ({ product, onFormat }) => {
  const [tab, setTab] = useState(null);
  const [selected, setSelected] = useState(0);
  const [wireframe, setWireframe] = useState(false);
  const [paint, setPaint] = useState('shaded');

  const previews = product.previews ?? [];
  const sellerImages = product.seller_images ?? [];
  const clips = (product.clips ?? []).filter((one) => one.passes?.length > 0);
  const isOwner = product.product_status === 'owner';

  const tabs = [
    ...previews.map((preview) => ({ key: preview.format, label: `.${preview.format}`, preview })),
    ...(sellerImages.length > 0
      ? [{ key: SELLER, label: 'From the seller', preview: null }]
      : []),
    ...(clips.length > 0
      ? [{ key: CLIPS, label: 'Animations', preview: null }]
      : []),
  ];

  const shown = tabs.find((one) => one.key === tab)
    ?? tabs.find((one) => one.preview?.images.length > 0)
    ?? tabs[0];

  const pick = (next) => {
    setTab(next);
    setSelected(0);
  };

  // The page shows measurements for whichever file is on screen, and the
  // gallery is what decides that.
  useEffect(() => {
    onFormat?.(shown?.preview?.format ?? null);
  }, [onFormat, shown?.preview?.format]);

  // Plain buttons, not a tablist: that role promises arrow-key navigation and a
  // matching panel, neither of which is here.
  const switcher = tabs.length > 1 && (
    <div className={styles.formats} role="group" aria-label="Which pictures">
      {tabs.map((one) => (
        <button
          key={one.key}
          type="button"
          aria-pressed={one.key === shown.key}
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

  if (shown.key === CLIPS) {
    const clip = clips[Math.min(selected, clips.length - 1)];
    const painted = PAINTS.filter(({ pass }) => clip.passes.some((one) => one.pass === pass));
    const showing = clip.passes.find((one) => one.pass === paint) ?? shadedFirst(clip);
    const name = nameOf(clip, selected);

    return (
      <div className={styles.stills}>
        <div className={styles.stage}>
          <img src={showing.url} alt={`${product.name}, ${name}, ${showing.pass}`} />

          {painted.length > 1 && (
            <div className={styles.paints}>
              {painted.map(({ pass, label }) => (
                <button
                  key={pass}
                  type="button"
                  className={pass === showing.pass ? styles.paintOn : styles.paint}
                  aria-pressed={pass === showing.pass}
                  onClick={() => setPaint(pass)}
                >
                  {label}
                </button>
              ))}
            </div>
          )}
        </div>

        {switcher}

        {clips.length > 1 && (
          <div className={styles.strip}>
            {clips.map((one, index) => (
              <button
                key={one.index}
                type="button"
                className={index === selected ? styles.selected : undefined}
                aria-label={nameOf(one, index)}
                aria-current={index === selected}
                onClick={() => setSelected(index)}
              >
                <img src={shadedFirst(one).url} alt="" loading="lazy" decoding="async" />
              </button>
            ))}
          </div>
        )}

        <div className={styles.badge}>
          {name}
          {typeof clip.seconds === 'number' && ` · ${clip.seconds}s`}
          {clip.frames && ` · ${clip.frames} frames`}
          {NOTES[showing.pass] && ` — ${NOTES[showing.pass]}`}
        </div>
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

  // Images survive a failed re-render, so they describe whatever the file was
  // last time one succeeded. Saying nothing would badge them as the file on
  // sale, which is the one thing this page must never get wrong.
  const stale = !fromSeller && shown.preview.status === 'failed';
  const image = images[Math.min(selected, images.length - 1)];
  const outline = fromSeller ? null : image.wireframe;
  const showOutline = wireframe && outline != null;
  // The record belongs to the image on screen, and the wireframe is attested
  // separately from the shaded view it was drawn beside.
  const record = showOutline ? outline.attestation_url : image.attestation_url;
  const alt = fromSeller
    ? `${product.name}, image ${image.sort + 1} from the seller`
    : `${product.name}, .${shown.preview.format} view ${image.sort + 1}`
      + (showOutline ? ' wireframe' : '');

  return (
    <div className={styles.stills}>
      <div className={styles.stage}>
        <img src={showOutline ? outline.url : image.url} alt={alt} />

        {outline && (
          <button
            type="button"
            className={showOutline ? styles.outlineOn : styles.outline}
            aria-pressed={showOutline}
            onClick={() => setWireframe(!wireframe)}
          >
            Wireframe
          </button>
        )}
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
              <img src={option.url} alt="" loading="lazy" decoding="async" />
            </button>
          ))}
        </div>
      )}

      {/* Only our own images are labelled. A note on the seller's reads as a
          disclaimer against them, and the tab already says whose they are. */}
      {!fromSeller && (
        stale ? (
          <div className={styles.stale}>
            <span>
              These images are from an earlier version of the .{shown.preview.format} file.
              Rendering the current one failed, so they may not match what you would download.
            </span>
            {isOwner && <span className={styles.hint}>{shown.preview.error}</span>}
          </div>
        ) : (
          <div className={styles.badge}>
            {record ? (
              <a
                href={record}
                target="_blank"
                rel="noreferrer"
                title="The camera, the file and the checksums this image was made from"
              >
                {rendered(shown.preview)}
              </a>
            ) : (
              <>{rendered(shown.preview)}</>
            )}
          </div>
        )
      )}
    </div>
  );
};

export default AttestedStills;
