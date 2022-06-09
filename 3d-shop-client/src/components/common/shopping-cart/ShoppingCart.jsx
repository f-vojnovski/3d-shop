import { Menu, MenuButton, MenuDivider, MenuItem } from '@szhsin/react-menu';
import { FaShoppingCart } from 'react-icons/fa';
import { useDispatch, useSelector } from 'react-redux';
import '@szhsin/react-menu/dist/transitions/slide.css';
import { MdOutlineClear } from 'react-icons/md';
import { IoBagCheckOutline } from 'react-icons/io5';
import { clearCart } from '../../../service/features/cartSlice';
import styles from './ShoppingCart.module.css';
import { useNavigate } from 'react-router-dom';

const ShoppingCart = () => {
  const cartItems = useSelector((state) => state.cart.products);
  const totalPrice = useSelector((state) => state.cart.total);

  const dispatch = useDispatch();
  const navigate = useNavigate();

  const onCheckoutClick = () => {
    navigate(`../checkout`);
  };
  const onClearCartClick = () => {
    dispatch(clearCart());
  };

  const renderedProducts = cartItems.map((product, i) => (
    <div className="row p-1" key={i}>
      <div className="col text-truncate">
        <span>
          <span className="bolded-label">${product.price}</span> -{' '}
          {product.name}
        </span>
      </div>
    </div>
  ));

  return (
    <span className="text-light">
      <Menu
        align="end"
        offsetY={3}
        menuClassName={styles.cart_menu}
        menuButton={
          <MenuButton>
            <FaShoppingCart className="large-font" />
          </MenuButton>
        }
        transition
      >
        <div className="row p-1">
          <div className="col">
            <span className="bolded-label">Total: ${totalPrice}</span>
          </div>
        </div>
        <MenuDivider />
        <MenuItem
          className="p-1 m-0 text-success bolded-label"
          onClick={() => onCheckoutClick()}
        >
          <IoBagCheckOutline className="pe-1" />
          Checkout
        </MenuItem>
        <MenuDivider />
        <div className="row p-1">
          <div className="col">
            <span className="bolded-label">In cart:</span>
          </div>
        </div>
        {renderedProducts}
        <MenuDivider />
        <MenuItem className="p-1 m-0 text-danger" onClick={() => onClearCartClick()}>
          <MdOutlineClear className="pe-1" /> Clear cart
        </MenuItem>
      </Menu>
    </span>
  );
};

export default ShoppingCart;
