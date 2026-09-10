import Header from './components/common/header/Header';
import { BrowserRouter as Router } from 'react-router-dom';
import { Suspense } from 'react';
import LoadingSpinner from './components/common/spinner/LoadingSpinner';
import CookiesConsentWrapper from './components/common/cookies-popup/CookiesConsentWrapper';
import { ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import RoutesWrapper from './routes/RouterWrapper';

const App = () => {
  return (
    <Suspense fallback={<LoadingSpinner />}>
      <Router>
        <div>
          <Header></Header>
          <RoutesWrapper />
          <CookiesConsentWrapper />
          <ToastContainer closeButton={true} position="bottom-center" />
        </div>
      </Router>
    </Suspense>
  );
};

export default App;
