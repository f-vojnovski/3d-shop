import { MdAddShoppingCart } from 'react-icons/md';
import { useDispatch } from 'react-redux';
import { toast } from 'react-toastify';
import { addToCart } from '../../../../service/features/cartSlice';

const AddToCartButton = (props) => {
  let product = props.product;

  const dispatch = useDispatch();

  const onAddToCartClick = () => {
    toast.success('Item added to cart');
    dispatch(addToCart(product));
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
