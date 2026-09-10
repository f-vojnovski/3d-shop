import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, vi } from 'vitest';
import Toasts from './Toasts';
import { clearToasts, toast } from './toastStore';

afterEach(() => {
  clearToasts();
  vi.useRealTimers();
});

describe('Toasts', () => {
  it('shows nothing until something is announced', () => {
    const { container } = render(<Toasts />);

    expect(container).toBeEmptyDOMElement();
  });

  it('shows a message that arrives after mounting', async () => {
    render(<Toasts />);

    toast.success('Previews are ready.');

    expect(await screen.findByText('Previews are ready.')).toBeInTheDocument();
  });

  it('keeps failures and successes visually distinct', async () => {
    render(<Toasts />);

    toast.success('Saved.');
    toast.error('Upload failed.');

    const saved = await screen.findByText('Saved.');
    const failed = await screen.findByText('Upload failed.');

    expect(saved.parentElement.className).not.toBe(failed.parentElement.className);
  });

  it('can be dismissed by hand', async () => {
    render(<Toasts />);
    toast.info('Working on it.');
    await screen.findByText('Working on it.');

    await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

    expect(screen.queryByText('Working on it.')).not.toBeInTheDocument();
  });

  it('clears itself so messages do not pile up forever', async () => {
    vi.useFakeTimers();
    render(<Toasts />);

    act(() => toast.success('Previews are ready.'));
    expect(screen.getByText('Previews are ready.')).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(5000));

    expect(screen.queryByText('Previews are ready.')).not.toBeInTheDocument();
  });
});
