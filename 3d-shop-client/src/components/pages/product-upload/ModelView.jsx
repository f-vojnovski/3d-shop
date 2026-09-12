import { ErrorBoundary } from 'react-error-boundary';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import StlModelDisplayer from '../../common/model-displayer/StlModelDisplayer';
import ModelLoaderErrorFallback from '../product-view/ModelLoaderErrorFallback';

const DISPLAYERS = {
  obj: ObjModelDisplayer,
  gltf: GltfModelDisplayer,
  stl: StlModelDisplayer,
};

/**
 * The file being framed. The probe stays with it wherever it is shown: a
 * capture has to come from the model actually being sold.
 */
const ModelView = ({ format, uri, probe, sync }) => {
  const Displayer = DISPLAYERS[format] ?? GltfModelDisplayer;

  return (
    <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback} resetKeys={[uri]}>
      <Displayer
        fileUrl={uri}
        isLocalFile
        probeRef={probe}
        sync={sync}
      />
    </ErrorBoundary>
  );
};

export default ModelView;
