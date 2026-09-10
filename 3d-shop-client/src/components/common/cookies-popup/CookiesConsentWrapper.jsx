import { useState } from 'react';
import { CONSENT_COOKIE_NAME } from '../../../consts';
import cookies from '../../../service/cookies/cookiesWrapper';
import styles from './CookiesConsentWrapper.module.css';

const CONSENT_DAYS = 150;

const CookiesConsentWrapper = () => {
  const [accepted, setAccepted] = useState(Boolean(cookies.get(CONSENT_COOKIE_NAME)));

  if (accepted) {
    return null;
  }

  const accept = () => {
    cookies.set(CONSENT_COOKIE_NAME, 'true', CONSENT_DAYS);
    setAccepted(true);
  };

  return (
    <div className={styles.banner} role="region" aria-label="Cookie consent">
      <span>This site uses cookies to keep you signed in.</span>
      <button type="button" className="btn btn-primary btn-sm" onClick={accept}>
        Accept cookies
      </button>
    </div>
  );
};

export default CookiesConsentWrapper;
