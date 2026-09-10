import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { notify } from '../../../service/features/toastSlice';
import {
  checkoutCart,
  clearCart,
  clearCheckoutError,
  removeFromCart,
} from '../../../service/features/cartSlice';
import { Link } from 'react-router-dom';
import { formatPrice } from '../../../service/util/formatPrice';
import styles from './CheckoutPage.module.css';

const CheckoutPage = () => {
  const products = useSelector((state) => state.cart.products);
  const total = useSelector((state) => state.cart.total);
  const cartStatus = useSelector((state) => state.cart.status);
  const error = useSelector((state) => state.cart.error);

  const dispatch = useDispatch();

  const navigate = useNavigate();

  const onCheckoutButtonClick = () => {
    dispatch(checkoutCart());
  };

  useEffect(() => {
    if (cartStatus === 'succeeded') {
      dispatch(notify('success', 'Checkout successful, enjoy using your newly acquired products!'));
      dispatch(clearCart());
      navigate('/purchases');
    }
  }, [cartStatus, dispatch, navigate]);

  useEffect(() => {
    if (cartStatus === 'failed') {
      dispatch(notify('error', error || 'There was a problem completing your purchase.'));
      dispatch(clearCheckoutError());
    }
  }, [cartStatus, error, dispatch]);

  const renderedProducts = products.map((product) => (
    <div className={styles.line} key={product.id}>
      {product.thumbnail_url ? (
        <img
          className={styles.thumb}
          src={product.thumbnail_url}
          alt={`${product.name} thumbnail`}
        />
      ) : (
        <div className={styles.thumb} />
      )}
      <div>
        <Link className={styles.name} to={`/product/${product.id}`}>
          {product.name}
        </Link>
        <div className={styles.price}>${formatPrice(product.price_cents)}</div>
      </div>
      <button
        className="btn btn-sm btn-outline-danger"
        onClick={() => dispatch(removeFromCart(product.id))}
      >
        Remove
      </button>
    </div>
  ));

  let content;

  if (products.length > 0) {
    content = (
      <>
        <div className={styles.list}>{renderedProducts}</div>
        <div className={styles.summary}>
          <span className={styles.total}>Total: ${formatPrice(total)}</span>
          <button className="btn btn-success" onClick={() => onCheckoutButtonClick()}>
            Proceed to payment
          </button>
        </div>
      </>
    );
  } else {
    content = <div className={styles.empty}>Shopping cart is empty.</div>;
  }

  return <div className="page-shell">{content}</div>;
};

export default CheckoutPage;
