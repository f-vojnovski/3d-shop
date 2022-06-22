import { useDispatch, useSelector } from 'react-redux';
import { toast } from 'react-toastify';
import { checkoutCart, clearCart } from '../../../service/features/cartSlice';
import ProductOverview from '../../common/product-preview/ProductOverview';

const CheckoutPage = () => {
  const products = useSelector((state) => state.cart.products);
  const total = useSelector((state) => state.cart.total);
  const cartStatus = useSelector((state) => state.cart.status);

  const dispatch = useDispatch();

  const onCheckoutButtonClick = () => {
    dispatch(checkoutCart());
  };

  if (cartStatus === 'succeeded') {
    toast.success('Checkout successfull, enjoy using your newly acquired products!');
    dispatch(clearCart());
  }

  if (cartStatus === 'error') {
    toast.error('There was an error when buying products');
  }

  const renderedProducts = products.map((product) => (
    <div className="row d-flex justify-content-center" key={product.id}>
      <div className="col-12 mb-2">
        <ProductOverview
          id={product.id}
          name={product.name}
          price={product.price}
        ></ProductOverview>
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
          <div className="col d-flex justify-content-end">
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
