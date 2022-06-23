import { Link } from 'react-router-dom';
import { API_URL } from '../../../consts';

const ProductOverview = (product) => {
  let thumbnailPath = `${API_URL}${product.thumbnailUrl}`;

  return (
    <div>
      <div className="card max-width-350">
        <div className="d-flex justify-content-center m-1">
          <img
            src={thumbnailPath}
            className="product-thumbnail"
            alt="Thumbnail not availbale"
          ></img>
        </div>
        <div className="row">
          <div className="col-6">
            <div className="ms-2">
              <Link to={`/product/${product.id}`} className="link-dark">
                <div>{product.name}</div>
              </Link>
              <div className="bolded-label">${product.price}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProductOverview;
