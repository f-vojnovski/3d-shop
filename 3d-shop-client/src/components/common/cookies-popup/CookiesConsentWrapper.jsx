import { useEffect, useRef, useState } from 'react';
import { CONSENT_COOKIE_NAME } from '../../../consts';
import cookies from '../../../service/cookies/cookiesWrapper';
import styles from './CookiesConsentWrapper.module.css';

const CONSENT_DAYS = 150;

const CookiesConsentWrapper = () => {
  const [accepted, setAccepted] = useState(Boolean(cookies.get(CONSENT_COOKIE_NAME)));
  const banner = useRef(null);

  // The banner floats, so the page has to be told how much of its bottom is
  // covered. Measured rather than guessed: the text wraps on a narrow screen.
  useEffect(() => {
    const root = document.documentElement;

    if (accepted || banner.current === null) {
      root.style.removeProperty('--banner-height');

      return undefined;
    }

    const element = banner.current;
    const apply = () => root.style.setProperty('--banner-height', `${element.offsetHeight}px`);

    apply();

    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(apply);
    observer?.observe(element);

    return () => {
      observer?.disconnect();
      root.style.removeProperty('--banner-height');
    };
  }, [accepted]);

  if (accepted) {
    return null;
  }

  const accept = () => {
    cookies.set(CONSENT_COOKIE_NAME, 'true', CONSENT_DAYS);
    setAccepted(true);
  };

  return (
    <div ref={banner} className={styles.banner} role="region" aria-label="Cookie consent">
      <span>This site uses cookies to keep you signed in.</span>
      <button type="button" className="btn btn-primary btn-sm" onClick={accept}>
        Accept cookies
      </button>
    </div>
  );
};

export default CookiesConsentWrapper;
