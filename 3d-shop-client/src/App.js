import "./App.css";
import Header from "./components/common/header/Header";
import SingleProductView from "./components/pages/model-page/SingleProductView";
import ModelsListPage from "./components/pages/models-list-page/ModelsListPage";
import RegisterPage from "./components/pages/register-page/RegisterPage";
import "bootstrap/dist/css/bootstrap.min.css";
import LoginPage from "./components/pages/login-page/LoginPage";
import {
  BrowserRouter as Router,
  Route,
  Routes,
} from "react-router-dom";
import { Fragment } from "react";

const App = () => {
  return (
    <Router>
      <div className="App">
        <Header></Header>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route
            path="/register"
            element={<RegisterPage />}
          />
          <Route
            path="/products"
            element={
              <Fragment>
                <ModelsListPage />
              </Fragment>
            }
          />
          <Route exact path="products/:productId" element={<SingleProductView/>}/>
        </Routes>
      </div>
    </Router>
  );
};

export default App;
