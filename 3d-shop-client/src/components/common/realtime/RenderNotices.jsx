import { useEffect, useRef } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { notify } from '../../../service/features/toastSlice';
import { fetchProductById } from '../../../service/features/productSlice';
import { customViewDrawn } from '../../../service/features/customViewSlice';
import { createEcho } from '../../../service/realtime/echo';

const RenderNotices = () => {
  const token = useSelector((state) => state.auth.token);
  const userId = useSelector((state) => state.auth.user?.id);
  const viewedProductId = useSelector((state) => state.product.product?.id);

  const dispatch = useDispatch();

  // Read at event time, so opening another product does not resubscribe.
  const viewed = useRef(viewedProductId);

  useEffect(() => {
    viewed.current = viewedProductId;
  }, [viewedProductId]);

  useEffect(() => {
    if (!token || !userId) {
      return undefined;
    }

    const echo = createEcho(token);
    const channel = `sellers.${userId}`;

    echo.private(channel).listen('.preview.render.finished', (event) => {
      if (event.status === 'ready') {
        dispatch(notify('success', `Previews are ready for ${event.productName}.`));
      } else {
        dispatch(notify('error', event.error || `Previews failed for ${event.productName}.`));
      }

      if (viewed.current === event.productId) {
        dispatch(fetchProductById(event.productId));
      }
    });

    // Its own channel: anyone signed in can ask for a view, not just sellers.
    const viewerChannel = `viewers.${userId}`;

    echo.private(viewerChannel).listen('.custom.view.drawn', (event) => {
      dispatch(customViewDrawn({
        id: event.viewId,
        pass: event.pass,
        status: event.status,
        url: event.url,
        error: event.error,
      }));

      if (event.status === 'failed') {
        dispatch(notify('error', event.error || 'That view could not be drawn.'));
      }
    });

    return () => {
      echo.leave(channel);
      echo.leave(viewerChannel);
      echo.disconnect();
    };
  }, [token, userId, dispatch]);

  return null;
};

export default RenderNotices;
