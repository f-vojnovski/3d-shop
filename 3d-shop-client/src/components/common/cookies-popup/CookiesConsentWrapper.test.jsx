import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { CONSENT_COOKIE_NAME } from '../../../consts';
import CookiesConsentWrapper from './CookiesConsentWrapper';

const height = () => document.documentElement.style.getPropertyValue('--banner-height');

describe('CookiesConsentWrapper', () => {
  beforeEach(() => {
    document.cookie = `${CONSENT_COOKIE_NAME}=; path=/; max-age=0`;
    document.documentElement.style.removeProperty('--banner-height');
  });

  /**
   * The banner floats over the page, so without this the bottom of every page
   * sits underneath it — on the landing page, a row of prices.
   */
  it('reserves its own height while it is on screen', () => {
    render(<CookiesConsentWrapper />);

    expect(screen.getByRole('region', { name: 'Cookie consent' })).toBeInTheDocument();
    expect(height()).not.toBe('');
  });

  it('gives the space back once it is accepted', async () => {
    render(<CookiesConsentWrapper />);

    await userEvent.click(screen.getByRole('button', { name: 'Accept cookies' }));

    expect(screen.queryByRole('region', { name: 'Cookie consent' })).not.toBeInTheDocument();
    expect(height()).toBe('');
  });

  it('reserves nothing for a visitor who already accepted', () => {
    document.cookie = `${CONSENT_COOKIE_NAME}=true; path=/`;

    render(<CookiesConsentWrapper />);

    expect(screen.queryByRole('region', { name: 'Cookie consent' })).not.toBeInTheDocument();
    expect(height()).toBe('');
  });
});
