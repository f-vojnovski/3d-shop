import { FaShoppingCart } from 'react-icons/fa';

const ShoppingCart = () => {
  return (
    <div className="shopping-cart bg-dark p-2 text-white bg-dark">
      <div className="d-flex justify-content-center align-items-center text-center">
        <FaShoppingCart />
      </div>
    </div>
  );
};

export default ShoppingCart;
