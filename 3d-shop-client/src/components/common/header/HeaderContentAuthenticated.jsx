import { useDispatch, useSelector } from 'react-redux';
import { Menu, MenuItem, MenuButton } from '@szhsin/react-menu';
import '@szhsin/react-menu/dist/index.css';
import { logoutUser } from '../../../service/features/authSlice';
import ShoppingCart from '../shopping-cart/ShoppingCart';
import '@szhsin/react-menu/dist/transitions/slide.css';
import { BsPersonCircle } from 'react-icons/bs';
import { Link } from 'react-router-dom';
import { MdLogout, MdOutlineAddCircle } from 'react-icons/md';
import { BsFillGridFill } from 'react-icons/bs';
import { GrMoney } from 'react-icons/gr';

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
        menuClassName="p-1"
        menuButton={
          <MenuButton>
            <BsPersonCircle className="me-2" />
            {auth.user.name}
          </MenuButton>
        }
        transition
      >
        <MenuItem className="p-1">
          <Link className="text-link" to="/upload">
            <MdOutlineAddCircle /> Upload product
          </Link>
        </MenuItem>
        <MenuItem className="p-1">
          <Link className="text-link" to="/my-products">
            <BsFillGridFill /> My products
          </Link>
        </MenuItem>
        <MenuItem className="p-1">
          <Link className="text-link" to="/my-sales">
            <GrMoney /> My sales
          </Link>
        </MenuItem>
        <MenuItem className="p-1" onClick={() => onLogoutClick()}>
          {' '}
          <MdLogout /> Logout
        </MenuItem>
      </Menu>
      <ShoppingCart />
    </div>
  );
};

export default HeaderContentAuthenticated;
