import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ClipPlayer from './ClipPlayer';

const clip = (index, name, passes) => ({
  index,
  name,
  seconds: 1,
  frames: 24,
  passes: passes.map((pass) => ({ pass, url: `/clips/${index}-${pass}.webp`, bytes: 1000 })),
});

describe('ClipPlayer', () => {
  it('shows the first clip painted normally', () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded', 'influence', 'bones'])]} />);

    expect(screen.getByRole('img')).toHaveAttribute('src', '/clips/0-shaded.webp');
  });

  it('switches which way the clip is painted', async () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded', 'influence', 'bones'])]} />);

    await userEvent.click(screen.getByRole('button', { name: 'Skeleton' }));

    expect(screen.getByRole('img')).toHaveAttribute('src', '/clips/0-bones.webp');
  });

  it('switches between clips', async () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded']), clip(1, 'Run', ['shaded'])]} />);

    await userEvent.click(screen.getByRole('button', { name: 'Run' }));

    expect(screen.getByRole('img')).toHaveAttribute('src', '/clips/1-shaded.webp');
  });

  /** One clip is not a choice, so it is not offered as one. */
  it('does not ask which clip when there is only one', () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded', 'bones'])]} />);

    expect(screen.queryByRole('button', { name: 'Walk' })).not.toBeInTheDocument();
  });

  it('only offers a paint that was actually drawn', () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded'])]} />);

    expect(screen.queryByRole('button', { name: 'Skeleton' })).not.toBeInTheDocument();
  });

  /** Exporters leave clips unnamed often enough that a blank would show. */
  it('names an unnamed clip rather than showing a blank', () => {
    render(<ClipPlayer clips={[clip(0, null, ['shaded']), clip(1, null, ['shaded'])]} />);

    expect(screen.getByRole('button', { name: 'Clip 1' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clip 2' })).toBeInTheDocument();
  });

  it('says what the bone colours mean, because nobody would guess', async () => {
    render(<ClipPlayer clips={[clip(0, 'Walk', ['shaded', 'influence'])]} />);

    await userEvent.click(screen.getByRole('button', { name: 'Which bone moves what' }));

    expect(screen.getByText(/coloured by the bone that pulls it/i)).toBeInTheDocument();
  });

  it('shows nothing when there are no clips', () => {
    const { container } = render(<ClipPlayer clips={[]} />);

    expect(container).toBeEmptyDOMElement();
  });
});
