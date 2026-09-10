import CookieConsent from 'react-cookie-consent';
import { CONSENT_COOKIE_NAME } from '../../../consts';

const CookiesConsentWrapper = () => {
  return (
    <CookieConsent
      location="bottom"
      buttonText="Accept cookies"
      cookieName={CONSENT_COOKIE_NAME}
      buttonClasses="btn btn-primary btn-sm"
      disableButtonStyles={true}
      style={{
        background: '#1f2428',
        borderTop: '1px solid #2b3237',
        color: '#e9edf0',
        alignItems: 'center',
      }}
      expires={150}
    >
      This website uses cookies to enhance the user experience.
    </CookieConsent>
  );
};

export default CookiesConsentWrapper;
