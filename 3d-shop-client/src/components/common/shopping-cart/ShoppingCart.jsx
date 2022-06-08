import { Menu, MenuButton, MenuDivider, MenuItem } from '@szhsin/react-menu';
import { FaShoppingCart } from 'react-icons/fa';
import { useSelector } from 'react-redux';
import '@szhsin/react-menu/dist/transitions/slide.css';

const ShoppingCart = () => {
  const onCheckoutClick = () => {};

  const cartItems = useSelector((state) => state.cart.products);
  const totalPrice = useSelector((state) => state.cart.total);

  console.log(cartItems);

  const renderedProducts = cartItems.map((product, i) => (
    <div className="row p-1" key={i}>
      <div className="col">
        <span>
          <span className="bolded-label">${product.price}</span> - {product.name}
        </span>
      </div>
    </div>
  ));

  return (
    <span className="text-light">
      <Menu
        align="end"
        offsetY={3}
        className="d-inline"
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
        <MenuItem className="p-1 m-0" onClick={() => onCheckoutClick()}>
          Checkout
        </MenuItem>
        <MenuDivider />
        <div className="row p-1">
          <div className="col">
            <span className="bolded-label">In cart:</span>
          </div>
        </div>
        {renderedProducts}
      </Menu>
    </span>
  );
};

export default ShoppingCart;
