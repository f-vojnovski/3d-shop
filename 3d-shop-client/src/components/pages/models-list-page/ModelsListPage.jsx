import ProductOverview from "../../common/product-preview/ProductOverview";
import { useSelector } from "react-redux";

const ModelsListPage = () => {
  const products = useSelector((state) => state.products);

  const renderedProducts = products.map((product, i) => (
    <div className="col-sm-6 col-md-3 mb-2" key={i}>
      <ProductOverview
        name={product.name}
        description={product.description}
        price={product.price}
      ></ProductOverview>
    </div>
  ));

  return (
    <div className="container-fluid max-width-1600">
      <div className="row">{renderedProducts}</div>
    </div>
  );
};

export default ModelsListPage;
