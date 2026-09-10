import { configureStore } from '@reduxjs/toolkit';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, vi } from 'vitest';
import { Provider } from 'react-redux';
import Toasts from './Toasts';
import toastsReducer, { notify } from '../../../service/features/toastSlice';

const mount = () => {
  const store = configureStore({ reducer: { toasts: toastsReducer } });

  render(
    <Provider store={store}>
      <Toasts />
    </Provider>
  );

  return (tone, message) => act(() => store.dispatch(notify(tone, message)));
};

afterEach(() => {
  vi.useRealTimers();
});

describe('Toasts', () => {
  // The live region has to exist before the first message, or it is not
  // announced when it appears.
  it('is present but silent until something is announced', () => {
    mount();

    const region = screen.getByRole('status');
    expect(region).toBeInTheDocument();
    expect(region).toBeEmptyDOMElement();
  });

  it('shows a message that arrives after mounting', async () => {
    const announce = mount();

    announce('success', 'Previews are ready.');

    expect(await screen.findByText('Previews are ready.')).toBeInTheDocument();
  });

  it('keeps failures and successes visually distinct', async () => {
    const announce = mount();

    announce('success', 'Saved.');
    announce('error', 'Upload failed.');

    const saved = await screen.findByText('Saved.');
    const failed = await screen.findByText('Upload failed.');

    expect(saved.parentElement.className).not.toBe(failed.parentElement.className);
  });

  it('can be dismissed by hand', async () => {
    const announce = mount();
    announce('info', 'Working on it.');
    await screen.findByText('Working on it.');

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

    expect(screen.queryByText('Working on it.')).not.toBeInTheDocument();
  });

  it('clears itself so messages do not pile up forever', async () => {
    vi.useFakeTimers();
    const announce = mount();

    announce('success', 'Previews are ready.');
    expect(screen.getByText('Previews are ready.')).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(5000));

    expect(screen.queryByText('Previews are ready.')).not.toBeInTheDocument();
  });

  it('dismisses only the message that was clicked', async () => {
    const announce = mount();
    announce('success', 'Saved.');
    announce('error', 'Upload failed.');
    await screen.findByText('Upload failed.');

    await userEvent.click(screen.getAllByRole('button', { name: 'Dismiss' })[0]);

    expect(screen.queryByText('Saved.')).not.toBeInTheDocument();
    expect(screen.getByText('Upload failed.')).toBeInTheDocument();
  });
});
