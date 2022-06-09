import { MdAddShoppingCart } from 'react-icons/md';
import { useDispatch, useSelector } from 'react-redux';
import { toast } from 'react-toastify';
import { addToCart } from '../../../../service/features/cartSlice';

const AddToCartButton = (props) => {
  let product = props.product;

  const dispatch = useDispatch();

  const totalPrice = useSelector((state) => state.cart.total);
  const lastAddToCartSuccessful = useSelector((state) => state.cart.lastActionSucceeded);

  const onAddToCartClick = () => {
    dispatch(addToCart(product));
    if (lastAddToCartSuccessful) {
      toast.success(`Item added to cart. Your current total is $${totalPrice}`);
    } else {
      toast.info(`This item is already in your shopping cart.`);
    }
  };

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
