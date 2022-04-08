import "./App.css";
import Header from "./components/common/header/Header";
import ModelView from "./components/pages/model-page/ModelView";
import ModelsListPage from "./components/pages/models-list-page/ModelsListPage"
import RegisterPage from "./components/pages/register-page/RegisterPage";
import "bootstrap/dist/css/bootstrap.min.css";
import LoginPage from "./components/pages/login-page/LoginPage";
import {
  BrowserRouter as Router,
  Route,
  Routes,
} from "react-router-dom";

const App = () => {
  return (
    <Router>
      <div className="App">
        <Header></Header>
        <Routes>
          <Route path="/login" element={<LoginPage/>} />
          <Route path="/register" element={<RegisterPage/>} />
          <Route path="/products" element={<ModelsListPage/>} />
          <Route path="/model" element={<ModelView/>} />
        </Routes>
      </div>
    </Router>
  );
};

export default App;
