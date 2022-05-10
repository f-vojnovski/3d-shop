import {
  fetchProductById,
  getSelectedProduct,
  selectProductById,
} from "../../../service/features/productsSlice";
import ModelDisplayer from "../../common/model-displayer/ModelDisplayer";
import styles from "./SingleProductView.module.css";
import { useParams } from "react-router-dom";
import { useSelector, useDispatch } from "react-redux";
import { useEffect } from "react";

const SingleProductView = ({ match }) => {
  const params = useParams();

  const productId = params.productId;

  const dispatch = useDispatch();
  const product = useSelector(getSelectedProduct);

  const productsStatus = useSelector(
    (state) => state.products.status
  );

  const error = useSelector(
    (state) => state.products.error
  );

  let content;

  useEffect(() => {
    if (productsStatus === "idle") {
      dispatch(fetchProductById(productId));
    }
  }, [productsStatus, dispatch]);

  if (productsStatus === "succeeded") {
    content = (
      <div>
        <div className={styles.title}>{product.name}</div>
        <div className={styles.model_details_container}>
          <div className={styles.model_container}>
            <div
              className={styles.model_container_dummy}
            ></div>
            <div className={styles.model}>
              <ModelDisplayer></ModelDisplayer>
            </div>
          </div>
          <div
            className={styles.model_info_container}
          ></div>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.view_container}>{content}</div>
  );
};

export default SingleProductView;
