import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import Lightbox from './Lightbox';

const Host = () => {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Open</button>
      {open && (
        <Lightbox
          src="https://example.test/full.png"
          alt="Delivery Truck, wireframe view you asked for"
          caption="wireframe · .gltf"
          onClose={() => setOpen(false)}
        />
      )}
    </>
  );
};

describe('Lightbox', () => {
  it('shows the image full size with its caption', () => {
    render(
      <Lightbox src="https://example.test/full.png" alt="A render" onClose={() => {}} caption="wireframe · .gltf" />,
    );

    expect(screen.getByRole('dialog')).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByAltText('A render')).toHaveAttribute('src', 'https://example.test/full.png');
    expect(screen.getByText('wireframe · .gltf')).toBeInTheDocument();
  });

  it('closes on Escape and hands focus back to the trigger', async () => {
    render(<Host />);
    const trigger = screen.getByRole('button', { name: 'Open' });
    await userEvent.click(trigger);

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await userEvent.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('closes when the backdrop is clicked', async () => {
    render(<Host />);
    await userEvent.click(screen.getByRole('button', { name: 'Open' }));

    await userEvent.click(screen.getByRole('dialog'));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  /** Clicking what you came to look at should not dismiss it. */
  it('stays open when the image itself is clicked', async () => {
    render(<Host />);
    await userEvent.click(screen.getByRole('button', { name: 'Open' }));

    await userEvent.click(screen.getByAltText('Delivery Truck, wireframe view you asked for'));

    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('lets the page scroll again once closed', async () => {
    render(<Host />);
    await userEvent.click(screen.getByRole('button', { name: 'Open' }));
    expect(document.body.style.overflow).toBe('hidden');

    await userEvent.keyboard('{Escape}');

    expect(document.body.style.overflow).not.toBe('hidden');
  });
});
