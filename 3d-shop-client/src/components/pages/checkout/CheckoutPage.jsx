import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-toastify';
import { checkoutCart, clearCart, removeFromCart } from '../../../service/features/cartSlice';
import ProductOverview from '../../common/product-preview/ProductOverview';
import { formatPrice } from '../../../service/util/formatPrice';

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
      toast.success('Checkout successful, enjoy using your newly acquired products!');
      dispatch(clearCart());
      navigate('/purchases');
    }
  }, [cartStatus, dispatch, navigate]);

  useEffect(() => {
    if (cartStatus === 'failed') {
      toast.error(error || 'There was a problem completing your purchase.');
    }
  }, [cartStatus, error]);

  const renderedProducts = products.map((product) => (
    <div className="row d-flex justify-content-center" key={product.id}>
      <div className="col-12 mb-2">
        <ProductOverview
          id={product.id}
          name={product.name}
          priceCents={product.price_cents}
          thumbnailUrl={product.thumbnail_url}
        ></ProductOverview>
        <button
          className="btn btn-sm btn-outline-danger mt-1"
          onClick={() => dispatch(removeFromCart(product.id))}
        >
          Remove
        </button>
      </div>
    </div>
  ));

  let content;

  if (products.length > 0) {
    content = (
      <div>
        <div className="d-flex justify-content-center">
          <div>{renderedProducts}</div>
        </div>
        <div className="row mb-2">
          <div className="col d-flex justify-content-end align-items-center gap-3">
            <span className="bolded-label">Total: ${formatPrice(total)}</span>
            <button className="btn btn-success" onClick={() => onCheckoutButtonClick()}>
              Proceed to payment
            </button>
          </div>
        </div>
      </div>
    );
  } else {
    content = (
      <div className="col">
        <h4>Shopping cart is empty.</h4>
      </div>
    );
  }

  return <div className="container-fluid max-width-1600">{content}</div>;
};

export default CheckoutPage;
