import Header from './components/common/header/Header';
import { BrowserRouter as Router } from 'react-router-dom';
import { Suspense } from 'react';
import LoadingSpinner from './components/common/spinner/LoadingSpinner';
import CookiesConsentWrapper from './components/common/cookies-popup/CookiesConsentWrapper';
import Toasts from './components/common/toast/Toasts';
import RoutesWrapper from './routes/RouterWrapper';
import RenderNotices from './components/common/realtime/RenderNotices';

const App = () => {
  return (
    <Suspense fallback={<LoadingSpinner />}>
      <Router>
        <div>
          <Header></Header>
          <RoutesWrapper />
          <RenderNotices />
          <CookiesConsentWrapper />
          <Toasts />
        </div>
      </Router>
    </Suspense>
  );
};

export default App;
