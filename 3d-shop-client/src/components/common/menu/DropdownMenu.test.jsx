import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import DropdownMenu, { MenuItem, MenuLink } from './DropdownMenu';

const open = async () => {
  await userEvent.click(screen.getByRole('button', { name: 'Account menu' }));
};

const renderMenu = (onLogout = () => {}) =>
  render(
    <MemoryRouter>
      <DropdownMenu ariaLabel="Account menu" label="Filip">
        <MenuLink to="/upload">Upload</MenuLink>
        <MenuLink to="/my-products">My uploads</MenuLink>
        <MenuItem onClick={onLogout}>Logout</MenuItem>
      </DropdownMenu>
    </MemoryRouter>
  );

describe('DropdownMenu', () => {
  it('says whether it is open', async () => {
    renderMenu();
    const trigger = screen.getByRole('button', { name: 'Account menu' });

    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await open();

    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('menu')).toBeInTheDocument();
  });

  // role="menu" promises keyboard behaviour; it used to promise it and not
  // deliver, leaving the panel unreachable without a mouse.
  it('moves focus into the panel and around it with the arrow keys', async () => {
    renderMenu();
    await open();

    const items = screen.getAllByRole('menuitem');
    expect(items[0]).toHaveFocus();

    await userEvent.keyboard('{ArrowDown}');
    expect(items[1]).toHaveFocus();

    await userEvent.keyboard('{ArrowUp}');
    expect(items[0]).toHaveFocus();
  });

  it('wraps at both ends and jumps with Home and End', async () => {
    renderMenu();
    await open();
    const items = screen.getAllByRole('menuitem');

    await userEvent.keyboard('{ArrowUp}');
    expect(items[items.length - 1]).toHaveFocus();

    await userEvent.keyboard('{Home}');
    expect(items[0]).toHaveFocus();

    await userEvent.keyboard('{End}');
    expect(items[items.length - 1]).toHaveFocus();
  });

  it('closes on Escape and hands focus back to the trigger', async () => {
    renderMenu();
    await open();

    await userEvent.keyboard('{Escape}');

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Account menu' })).toHaveFocus();
  });

  it('opens from the keyboard with ArrowDown', async () => {
    renderMenu();
    screen.getByRole('button', { name: 'Account menu' }).focus();

    await userEvent.keyboard('{ArrowDown}');

    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(screen.getAllByRole('menuitem')[0]).toHaveFocus();
  });

  it('runs an item and closes', async () => {
    const onLogout = vi.fn();
    renderMenu(onLogout);
    await open();

    await userEvent.click(screen.getByRole('menuitem', { name: 'Logout' }));

    expect(onLogout).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('shows a badge only when there is something to count', async () => {
    const { rerender } = render(
      <MemoryRouter>
        <DropdownMenu ariaLabel="Cart" label="cart" badge={0}>
          <MenuItem>Checkout</MenuItem>
        </DropdownMenu>
      </MemoryRouter>
    );

    expect(screen.getByRole('button', { name: 'Cart' })).toHaveTextContent(/^cart$/);

    rerender(
      <MemoryRouter>
        <DropdownMenu ariaLabel="Cart" label="cart" badge={3}>
          <MenuItem>Checkout</MenuItem>
        </DropdownMenu>
      </MemoryRouter>
    );

    expect(screen.getByRole('button', { name: 'Cart' })).toHaveTextContent('3');
  });
});
