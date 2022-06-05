import cookies from './cookiesWrapper';
import { CONSENT_COOKIE_NAME } from '../../consts';

export default function checkIfUserConsentedToCookies() {
  return cookies.get(CONSENT_COOKIE_NAME);
}
