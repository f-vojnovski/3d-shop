import { orderedReleases } from './releases';

describe('orderedReleases', () => {
  it('puts the most recent handover first, whatever order it arrives in', () => {
    const ordered = orderedReleases([
      { replaced_at: '2026-01-02T10:00:00Z' },
      { replaced_at: '2026-03-14T10:00:00Z' },
    ]);

    expect(ordered.map((one) => one.replaced_at)).toEqual([
      '2026-03-14T10:00:00Z',
      '2026-01-02T10:00:00Z',
    ]);
  });

  it('does not reorder the array it was given', () => {
    const given = [
      { replaced_at: '2026-01-02T10:00:00Z' },
      { replaced_at: '2026-03-14T10:00:00Z' },
    ];

    orderedReleases(given);

    expect(given[0].replaced_at).toBe('2026-01-02T10:00:00Z');
  });

  it('copes with a product that was never replaced', () => {
    expect(orderedReleases(undefined)).toEqual([]);
  });
});
