import { MdPhotoCamera } from 'react-icons/md';
import { ErrorBoundary } from 'react-error-boundary';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import ModelLoaderErrorFallback from '../product-view/ModelLoaderErrorFallback';
import styles from './ProductUpload.module.css';

const CaptureStage = ({ format, uri, probe, onCapture }) => {
  const Displayer = format === 'obj' ? ObjModelDisplayer : GltfModelDisplayer;

  return (
    <div className={styles.stage}>
      <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback} resetKeys={[uri]}>
        <Displayer fileUrl={uri} isLocalFile probeRef={probe} />
      </ErrorBoundary>

      <button
        type="button"
        className={styles.shutter}
        title="Capture this view"
        aria-label="Capture this view"
        onClick={onCapture}
      >
        <MdPhotoCamera />
      </button>
    </div>
  );
};

export default CaptureStage;
