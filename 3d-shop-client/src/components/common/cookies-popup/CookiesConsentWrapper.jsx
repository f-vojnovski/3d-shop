import CookieConsent from 'react-cookie-consent';
import { CONSENT_COOKIE_NAME } from '../../../consts';

const CookiesConsentWrapper = () => {
  return (
    <CookieConsent
      location="bottom"
      buttonText="Accept cookies"
      cookieName={CONSENT_COOKIE_NAME}
      buttonClasses="btn btn-primary"
      overlay="true"
      expires={150}
      onAccept={(acceptedByScrolling) => {
        if (!acceptedByScrolling) {
          alert('User consented to using cookies');
        }
      }}
    >
      This website uses cookies to enhance the user experience.
    </CookieConsent>
  );
};

export default CookiesConsentWrapper;
