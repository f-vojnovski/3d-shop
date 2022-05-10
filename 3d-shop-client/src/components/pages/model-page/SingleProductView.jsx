import { useSelector } from "react-redux";
import { selectProductById } from "../../../service/features/productsSlice";
import ModelDisplayer from "../../common/model-displayer/ModelDisplayer";
import styles from "./SingleProductView.module.css";
import { useParams } from "react-router-dom";

const SingleProductView = ({match}) => {  
  const params = useParams();

  const productId = params.productId;

  const product = useSelector((state) => selectProductById(state, productId))

  if (!product) {
    return <div>
      Couldn't find product.
    </div>
  }

  return (
    <div className={styles.view_container}>
      <div className={styles.title}>
        Low-poly human cowboy model
      </div>
      <div className={styles.model_details_container}>
        <div className={styles.model_container}>
          <div
            className={styles.model_container_dummy}
          ></div>
          <div className={styles.model}>
            <ModelDisplayer></ModelDisplayer>
          </div>
        </div>
        <div className={styles.model_info_container}>
        </div>
      </div>
    </div>
  );
};

export default SingleProductView;
