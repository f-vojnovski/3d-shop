import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import FileHistory from './FileHistory';

const release = (overrides = {}) => ({
  replaced_at: '2026-03-14T10:00:00Z',
  note: 'Fixed the scale.',
  sha256: 'abc123',
  facts: { faces: 213347 },
  images: [],
  ...overrides,
});

const older = release({ replaced_at: '2026-01-02T10:00:00Z', sha256: 'def456' });

const click = (name) => userEvent.click(screen.getByRole('button', { name }));

/** Stands in for the page, which owns which release is on the stage. */
const Harness = ({ releases }) => {
  const [viewing, setViewing] = useState(null);

  return (
    <>
      <FileHistory format="obj" releases={releases} viewing={viewing} onView={setViewing} />
      <output>{viewing === null ? 'latest' : `release ${viewing}`}</output>
    </>
  );
};

describe('FileHistory', () => {
  it('says nothing when a file was never replaced', () => {
    const { container } = render(
      <FileHistory format="obj" releases={[]} viewing={null} onView={() => {}} />
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('leads with when the current file took over', () => {
    render(<FileHistory format="obj" releases={[release()]} viewing={null} onView={() => {}} />);

    expect(screen.getByText('Last updated on 14 March 2026')).toBeInTheDocument();
  });

  it('keeps the release list behind a button rather than on the page', async () => {
    render(<Harness releases={[release()]} />);

    expect(screen.queryByText(/Until 14 March 2026/)).not.toBeInTheDocument();

    await click('View older releases');

    expect(screen.getByText(/Until 14 March 2026/)).toBeInTheDocument();
    expect(screen.getByText(/213,347 faces/)).toBeInTheDocument();
  });

  /** Choosing a release is a request to the page, not something shown here. */
  it('hands the chosen release up rather than rendering it', async () => {
    const onView = vi.fn();

    render(
      <FileHistory format="obj" releases={[release(), older]} viewing={null} onView={onView} />
    );

    await click('View older releases');
    await click(/Until 2 January 2026/);

    expect(onView).toHaveBeenCalledWith(1);
  });

  it('offers the way back and the way sideways while a release is up', () => {
    render(<FileHistory format="obj" releases={[release()]} viewing={0} onView={() => {}} />);

    expect(screen.getByText('Showing the release until 14 March 2026')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Back to latest' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View another release' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'View older releases' })).not.toBeInTheDocument();
  });

  it('returns the page to the current file', async () => {
    render(<Harness releases={[release()]} />);

    await click('View older releases');
    await click(/Until 14 March 2026/);
    expect(screen.getByText('release 0')).toBeInTheDocument();

    await click('Back to latest');

    expect(screen.getByText('latest')).toBeInTheDocument();
    expect(screen.getByText('Last updated on 14 March 2026')).toBeInTheDocument();
  });

  it('moves between releases without returning to the current file first', async () => {
    render(<Harness releases={[release(), older]} />);

    await click('View older releases');
    await click(/Until 14 March 2026/);
    await click('View another release');
    await click(/Until 2 January 2026/);

    expect(screen.getByText('release 1')).toBeInTheDocument();
  });
});
