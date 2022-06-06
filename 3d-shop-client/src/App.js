import './App.css';
import Header from './components/common/header/Header';
import 'bootstrap/dist/css/bootstrap.min.css';
import { BrowserRouter as Router } from 'react-router-dom';
import { Suspense } from 'react';
import CookiesConsentWrapper from './components/common/cookies-popup/CookiesConsentWrapper';
import { ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import RoutesWrapper from './routes/RouterWrapper';

const App = () => {
  return (
    <Suspense fallback={<div>Loading</div>}>
      <Router>
        <div className="App">
          <Header></Header>
          <RoutesWrapper />
          <CookiesConsentWrapper />
          <ToastContainer closeButton={true} position="bottom-right" />
        </div>
      </Router>
    </Suspense>
  );
};

export default App;
