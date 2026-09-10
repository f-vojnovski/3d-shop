import { MdAddShoppingCart } from 'react-icons/md';
import { useDispatch, useSelector } from 'react-redux';
import { notify } from '../../../../service/features/toastSlice';
import { addToCart } from '../../../../service/features/cartSlice';

const AddToCartButton = (props) => {
  let product = props.product;

  const dispatch = useDispatch();

  const productsInCart = useSelector((state) => state.cart.products);

  const onAddToCartClick = () => {
    if (!productsInCart.some((e) => e.id == product.id)) {
      dispatch(addToCart(product));
      dispatch(notify('success', `Item added to cart.`));
    } else {
      dispatch(notify('info', `This item is already in your shopping cart.`));
    }
  };

  if (product.product_status === 'owner') {
    return (
      <button className="btn btn-primary text-nowrap w-100" disabled={true}>
        You are the owner of this product
      </button>
    );
  }

  if (product.product_status === 'purchased') {
    return (
      <button className="btn btn-primary text-nowrap w-100" disabled={true}>
        You have purchased this product
      </button>
    );
  }

  if (product.unlisted) {
    return (
      <button className="btn btn-primary text-nowrap w-100" disabled={true}>
        No longer for sale
      </button>
    );
  }

  if (productsInCart.find((x) => x.id === product.id)) {
    return (
      <button className="btn btn-primary text-nowrap w-100" disabled={true}>
        Product is in cart
      </button>
    );
  }

  return (
    <button
      className="btn btn-primary text-nowrap w-100"
      onClick={() => onAddToCartClick()}
    >
      <MdAddShoppingCart className="me-2 large-font" />
      Add to cart
    </button>
  );
};

export default AddToCartButton;
