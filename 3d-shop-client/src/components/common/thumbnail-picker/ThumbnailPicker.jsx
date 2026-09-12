import { useDispatch, useSelector } from 'react-redux';
import { addThumbnails, removeThumbnail } from '../../../service/features/productSlice';
import styles from './ThumbnailPicker.module.css';

const ThumbnailPicker = ({ product }) => {
  const dispatch = useDispatch();
  const saving = useSelector((state) => state.product.savingThumbnails);

  const thumbnails = product.thumbnails ?? [];
  const uploaded = product.seller_images ?? [];
  const rendered = (product.previews ?? []).flatMap((preview) =>
    (preview.images ?? []).map((image) => ({ ...image, format: preview.format }))
  );

  const add = (id) => dispatch(addThumbnails({ productId: product.id, from: [id] }));

  const group = (label, images, describe) =>
    images.length > 0 && (
      <>
        <p className={styles.hint}>{label}</p>
        <div className={styles.grid}>
          {images.map((image) => (
            <button
              key={image.id}
              type="button"
              className={styles.add}
              disabled={saving}
              title={describe(image)}
              aria-label={`Add to thumbnails: ${describe(image)}`}
              onClick={() => add(image.id)}
            >
              <img src={image.url} alt="" loading="lazy" decoding="async" />
            </button>
          ))}
        </div>
      </>
    );

  return (
    <>
      {thumbnails.length > 0 ? (
        <div className={styles.grid}>
          {thumbnails.map((thumbnail, index) => (
            <div key={thumbnail.id} className={styles.current}>
              <img
                src={thumbnail.url}
                alt={`Thumbnail ${index + 1}`}
                loading="lazy"
                decoding="async"
              />
              {!thumbnail.scaled && <span className={styles.working}>Shrinking</span>}
              <button
                type="button"
                className={styles.remove}
                disabled={saving || thumbnails.length === 1}
                title={
                  thumbnails.length === 1
                    ? 'A listing needs a picture. Add another first.'
                    : 'Remove'
                }
                aria-label={`Remove thumbnail ${index + 1}`}
                onClick={() =>
                  dispatch(removeThumbnail({ productId: product.id, fileId: thumbnail.id }))
                }
              >
                &times;
              </button>
            </div>
          ))}
        </div>
      ) : (
        <p className={styles.hint}>Nothing on the card yet.</p>
      )}

      {group('Uploaded preview images', uploaded, () => 'Your own picture')}
      {group('Rendered views', rendered, (image) => `Rendered from the .${image.format} file`)}

      <input
        className="form-control form-control-sm"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp"
        disabled={saving}
        aria-label="Upload thumbnails"
        onChange={(event) => {
          const files = Array.from(event.target.files ?? []);

          if (files.length > 0) {
            dispatch(addThumbnails({ productId: product.id, files }));
            event.target.value = '';
          }
        }}
      />
    </>
  );
};

export default ThumbnailPicker;
