import { useDispatch, useSelector } from 'react-redux';
import { Menu, MenuItem, MenuButton } from '@szhsin/react-menu';
import '@szhsin/react-menu/dist/index.css';
import { logoutUser } from '../../../service/features/authSlice';
const HeaderContentAuthenticated = () => {
  const auth = useSelector((state) => state.auth);
  const dispatch = useDispatch();

  const onLogoutClick = () => {
    dispatch(logoutUser(auth.token));
  };

  return (
    <div>
      <Menu className="me-2" menuButton={<MenuButton>{auth.user.name}</MenuButton>}>
        <MenuItem onClick={() => onLogoutClick()}>Logout</MenuItem>
      </Menu>
    </div>
  );
};

export default HeaderContentAuthenticated;
