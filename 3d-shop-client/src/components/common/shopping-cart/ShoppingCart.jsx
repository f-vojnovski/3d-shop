import { FaShoppingCart } from 'react-icons/fa';
import { MdOutlineClear } from 'react-icons/md';
import { IoBagCheckOutline } from 'react-icons/io5';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { formatPrice } from '../../../service/util/formatPrice';
import { clearCart } from '../../../service/features/cartSlice';
import DropdownMenu, {
  MenuDivider,
  MenuHeading,
  MenuItem,
  MenuRow,
} from '../menu/DropdownMenu';

const ShoppingCart = () => {
  const cartItems = useSelector((state) => state.cart.products);
  const totalPrice = useSelector((state) => state.cart.total);

  const dispatch = useDispatch();
  const navigate = useNavigate();

  return (
    <DropdownMenu
      ariaLabel={`Cart, ${cartItems.length} item${cartItems.length === 1 ? '' : 's'}`}
      badge={cartItems.length}
      label={<FaShoppingCart />}
    >
      <MenuHeading>Total ${formatPrice(totalPrice)}</MenuHeading>

      {cartItems.length === 0 ? (
        <MenuHeading>Your cart is empty</MenuHeading>
      ) : (
        cartItems.map((product) => (
          <MenuRow
            key={product.id}
            label={product.name}
            amount={`$${formatPrice(product.price_cents)}`}
          />
        ))
      )}

      <MenuDivider />

      <MenuItem onClick={() => navigate('/checkout')}>
        <IoBagCheckOutline /> Checkout
      </MenuItem>

      {cartItems.length > 0 && (
        <MenuItem danger onClick={() => dispatch(clearCart())}>
          <MdOutlineClear /> Clear cart
        </MenuItem>
      )}
    </DropdownMenu>
  );
};

export default ShoppingCart;
