import { Link } from "react-router-dom";

const ProductOverview = (product) => {
  return (
    <div>
      <div className="card max-width-350">
        <div className="d-flex justify-content-center m-1">
          <img
            src="https://placeholder.pics/svg/300/E8E8E8/000000/Thumbnail%20not%20available"
            className="img-fluid"
            alt="Thumbnail not availbale"
          ></img>
        </div>
        <div className="row">
          <div className="col-6">
            <div className="ms-2">
              <Link
                to={`/products/${product.id}`}
                className="link-dark"
              >
                <div>{product.name}</div>
              </Link>
              <div className="bolded-label">
                ${product.price}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProductOverview;
