import ModelDisplayer from "../../common/model-displayer/ModelDisplayer";
import styles from "./ModelView.module.css";

const ModelView = () => {
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

export default ModelView;
