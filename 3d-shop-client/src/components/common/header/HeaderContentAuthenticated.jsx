import { useDispatch, useSelector } from 'react-redux';
import { BsPersonCircle, BsFillGridFill, BsCollection } from 'react-icons/bs';
import { MdLogout, MdOutlineAddCircle } from 'react-icons/md';
import { GrMoney } from 'react-icons/gr';
import { logoutUser } from '../../../service/features/authSlice';
import ShoppingCart from '../shopping-cart/ShoppingCart';
import DropdownMenu, { MenuDivider, MenuItem, MenuLink } from '../menu/DropdownMenu';

const HeaderContentAuthenticated = () => {
  const auth = useSelector((state) => state.auth);
  const dispatch = useDispatch();

  return (
    <>
      <DropdownMenu
        ariaLabel="Account menu"
        label={
          <>
            <BsPersonCircle /> {auth.user.name}
          </>
        }
      >
        <MenuLink to="/upload">
          <MdOutlineAddCircle /> Upload product
        </MenuLink>
        <MenuLink to="/my-products">
          <BsFillGridFill /> My uploads
        </MenuLink>
        <MenuLink to="/purchases">
          <BsCollection /> My purchases
        </MenuLink>
        <MenuLink to="/my-sales">
          <GrMoney /> My sales
        </MenuLink>

        <MenuDivider />

        <MenuItem danger onClick={() => dispatch(logoutUser(auth.token))}>
          <MdLogout /> Logout
        </MenuItem>
      </DropdownMenu>

      <ShoppingCart />
    </>
  );
};

export default HeaderContentAuthenticated;
