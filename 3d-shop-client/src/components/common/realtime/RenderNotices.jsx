import { useEffect, useRef } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { toast } from 'react-toastify';
import { fetchProductById } from '../../../service/features/productSlice';
import { createEcho } from '../../../service/realtime/echo';

const RenderNotices = () => {
  const token = useSelector((state) => state.auth.token);
  const sellerId = useSelector((state) => state.auth.user?.id);
  const viewedProductId = useSelector((state) => state.product.product?.id);

  const dispatch = useDispatch();

  // Read at event time, so opening another product does not resubscribe.
  const viewed = useRef(viewedProductId);

  useEffect(() => {
    viewed.current = viewedProductId;
  }, [viewedProductId]);

  useEffect(() => {
    if (!token || !sellerId) {
      return undefined;
    }

    const echo = createEcho(token);
    const channel = `sellers.${sellerId}`;

    echo.private(channel).listen('.preview.render.finished', (event) => {
      if (event.status === 'ready') {
        toast.success(`Previews are ready for ${event.productName}.`);
      } else {
        toast.error(event.error || `Previews failed for ${event.productName}.`);
      }

      if (viewed.current === event.productId) {
        dispatch(fetchProductById(event.productId));
      }
    });

    return () => {
      echo.leave(channel);
      echo.disconnect();
    };
  }, [token, sellerId, dispatch]);

  return null;
};

export default RenderNotices;
