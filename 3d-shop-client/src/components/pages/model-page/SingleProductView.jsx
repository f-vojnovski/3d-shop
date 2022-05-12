import ModelDisplayer from "../../common/model-displayer/ModelDisplayer";
import styles from "./SingleProductView.module.css";
import { useParams } from "react-router-dom";
import { useSelector, useDispatch } from "react-redux";
import { useEffect } from "react";
import {
  selectProduct,
  fetchProductById,
  fetchProductUrl,
} from "../../../service/features/productSlice";

const SingleProductView = () => {
  const params = useParams();
  const productId = params.productId;

  const dispatch = useDispatch();
  const product = useSelector(selectProduct);

  const productStatus = useSelector(
    (state) => state.product.status
  );

  const error = useSelector((state) => state.product.error);

  let content;

  useEffect(() => {
    if (productStatus === "idle") {
      dispatch(fetchProductById(productId));
    }
  }, [productStatus, dispatch, productId]);

  useEffect(() => {
    if (productStatus === "idle") {
      dispatch(fetchProductUrl(productId));
    }
  }, [productStatus, dispatch, productId]);

  if (productStatus === "succeeded") {
    content = (
      <div>
        <div className={styles.title}>
          {product.product.name}
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
