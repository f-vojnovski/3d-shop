import { useDispatch, useSelector } from 'react-redux';
import { Menu, MenuItem, MenuButton } from '@szhsin/react-menu';
import '@szhsin/react-menu/dist/index.css';
import { logoutUser } from '../../../service/features/authSlice';
import ShoppingCart from '../shopping-cart/ShoppingCart';
import '@szhsin/react-menu/dist/transitions/slide.css';

const HeaderContentAuthenticated = () => {
  const auth = useSelector((state) => state.auth);
  const dispatch = useDispatch();

  const onLogoutClick = () => {
    dispatch(logoutUser(auth.token));
  };

  return (
    <div className="me-2">
      <Menu
        offsetY={3}
        align="end"
        className="me-2 d-inline"
        menuButton={<MenuButton>{auth.user.name}</MenuButton>}
        transition
      >
        <MenuItem onClick={() => onLogoutClick()}>Logout</MenuItem>
      </Menu>
      <ShoppingCart />
    </div>
  );
};

export default HeaderContentAuthenticated;
