import { useMemo } from 'react';
import { fitToView } from './fitToView';

export default function useFitToView(object) {
  return useMemo(() => fitToView(object), [object]);
}
