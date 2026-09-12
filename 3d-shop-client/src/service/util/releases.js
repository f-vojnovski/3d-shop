/** Newest first, so the most recent handover dates the file on sale now. */
export const orderedReleases = (replaced) =>
  [...(replaced ?? [])].sort((a, b) => new Date(b.replaced_at) - new Date(a.replaced_at));
