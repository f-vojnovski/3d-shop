import { useCallback, useEffect, useState } from 'react';
import { useDispatch } from 'react-redux';
import { Link, useSearchParams } from 'react-router-dom';
import { getRequestWithToken } from '../../../service/api/axiosClient';
import { useSelector } from 'react-redux';
import { orderSettled } from '../../../service/features/cartSlice';
import { notify } from '../../../service/features/toastSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import { formatPrice } from '../../../service/util/formatPrice';
import styles from './OrderComplete.module.css';

const SETTLED = ['paid', 'failed', 'expired', 'refunded'];

/**
 * Where the gateway sends the buyer back to. The payment is confirmed to us by
 * webhook, which usually lands after this page opens, so it polls rather than
 * assuming the redirect means success.
 */
const OrderComplete = () => {
  const [params] = useSearchParams();
  const orderId = params.get('order');
  const token = useSelector((state) => state.auth.token);
  const dispatch = useDispatch();

  const [order, setOrder] = useState(null);
  const [gaveUp, setGaveUp] = useState(false);

  const poll = useCallback(async () => {
    const response = await getRequestWithToken(`/api/orders/${orderId}`, token);

    return response.data;
  }, [orderId, token]);

  useEffect(() => {
    if (!orderId || !token) {
      return undefined;
    }

    let attempts = 0;
    let live = true;

    const tick = async () => {
      attempts += 1;

      try {
        const latest = await poll();

        if (!live) {
          return;
        }

        setOrder(latest);

        if (SETTLED.includes(latest.status)) {
          if (latest.status === 'paid') {
            dispatch(orderSettled());
            dispatch(notify('success', 'Payment received. Your files are ready.'));
          }

          return;
        }
      } catch {
        // Keep polling: a failed check is not a failed payment.
      }

      if (attempts >= 20) {
        setGaveUp(true);
        return;
      }

      if (live) {
        timer = setTimeout(tick, 1500);
      }
    };

    let timer = setTimeout(tick, 300);

    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [orderId, token, poll, dispatch]);

  if (!orderId) {
    return <p className={styles.page}>No order to show.</p>;
  }

  if (order?.status === 'paid') {
    return (
      <div className={styles.page}>
        <h1 className={styles.heading}>Payment received</h1>
        <p>
          You paid ${formatPrice(order.subtotal_cents)} for{' '}
          {order.items.length === 1 ? '1 model' : `${order.items.length} models`}.
        </p>
        <Link className="btn btn-primary" to="/purchases">
          Go to your files
        </Link>
      </div>
    );
  }

  if (order && SETTLED.includes(order.status)) {
    return (
      <div className={styles.page}>
        <h1 className={styles.heading}>Payment not completed</h1>
        <p>Nothing was charged. Your cart is still here if you want to try again.</p>
        <Link className="btn btn-primary" to="/checkout">
          Back to the cart
        </Link>
      </div>
    );
  }

  if (gaveUp) {
    return (
      <div className={styles.page}>
        <h1 className={styles.heading}>Still waiting on your bank</h1>
        <p>
          This can take a minute longer than usual. Nothing is lost — your files appear
          under your purchases as soon as the payment clears.
        </p>
        <Link className="btn btn-primary" to="/purchases">
          Check your purchases
        </Link>
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <LoadingSpinner />
      <p>Confirming your payment.</p>
    </div>
  );
};

export default OrderComplete;
