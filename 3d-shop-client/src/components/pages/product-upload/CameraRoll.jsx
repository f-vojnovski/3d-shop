import { MdCameraAlt, MdChevronLeft, MdChevronRight, MdClose, MdRefresh } from 'react-icons/md';
import styles from './ProductUpload.module.css';

const round = (n) => (Math.round(n * 10) / 10).toFixed(1);

const CameraRoll = ({ shots, thumbnailIndex, onRemove, onRetake, onMove, onThumbnail }) => (
  <div className={styles.roll}>
    {shots.map((shot, index) => (
      <figure key={shot.id} className={styles.shot}>
        <img src={shot.snapshot} alt={`View ${index + 1}`} />

        {index === thumbnailIndex && <span className={styles.thumbnailFlag}>Thumbnail</span>}

        <figcaption className={styles.shotAngle}>
          {shot.camera.position.map(round).join(', ')} · {round(shot.camera.fov)}°
        </figcaption>

        <div className={styles.shotTools}>
          <button type="button" title="Move earlier" onClick={() => onMove(index, -1)}>
            <MdChevronLeft />
          </button>
          <button type="button" title="Retake from this view" onClick={() => onRetake(index)}>
            <MdRefresh />
          </button>
          <button type="button" title="Use as thumbnail" onClick={() => onThumbnail(index)}>
            <MdCameraAlt />
          </button>
          <button type="button" title="Remove" onClick={() => onRemove(index)}>
            <MdClose />
          </button>
          <button type="button" title="Move later" onClick={() => onMove(index, 1)}>
            <MdChevronRight />
          </button>
        </div>
      </figure>
    ))}
  </div>
);

export default CameraRoll;
