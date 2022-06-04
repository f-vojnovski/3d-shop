import CookieConsent from 'react-cookie-consent';

const CookiesConsentWrapper = () => {
  return (
    <CookieConsent
      location="bottom"
      buttonText="Accept cookies"
      cookieName="userConsentToUsingCookies"
      buttonClasses="btn btn-primary"
      overlay="true"
      expires={150}
      onAccept={(acceptedByScrolling) => {
        if (!acceptedByScrolling) {
          alert('Accept was triggered by user scrolling');
        }
      }}
    >
      This website uses cookies to enhance the user experience.
    </CookieConsent>
  );
};

export default CookiesConsentWrapper;
