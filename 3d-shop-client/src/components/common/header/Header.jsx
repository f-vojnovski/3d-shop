import HeaderLink from "./HeaderLink";
import styles from "./Header.module.css";

const Header = () => {
  return (
    <nav className="navbar navbar-dark bg-dark mb-2 ps-3 pe-3 pt-3 pb-3 d-flex">
        <span className={styles.title}>3D MARKETPLACE</span>
        <span className="justify-content-end">
          <HeaderLink link="login">LOGIN</HeaderLink>
          <HeaderLink link="register">REGISTER</HeaderLink>
        </span>
    </nav>
  );
};

export default Header;
