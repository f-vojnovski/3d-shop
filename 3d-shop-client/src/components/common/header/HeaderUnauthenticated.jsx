import HeaderLink from './HeaderLink';

const HeaderContentUnauthenticated = () => {
  return (
    <div>
      <HeaderLink link="login">LOGIN</HeaderLink>
      <HeaderLink link="register">REGISTER</HeaderLink>
    </div>
  );
};

export default HeaderContentUnauthenticated;
