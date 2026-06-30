/**
 * View mode store tests.
 */

import { useViewModeStore, type ViewMode } from '../stores/view-mode-store';

describe('useViewModeStore', () => {
  beforeEach(() => {
    // Reset store state before each test
    useViewModeStore.setState({ viewMode: 'grid' });
  });

  it('initializes with grid view mode', () => {
    const { viewMode } = useViewModeStore.getState();
    expect(viewMode).toBe('grid');
  });

  it('sets view mode to list', () => {
    const { setViewMode } = useViewModeStore.getState();
    setViewMode('list');

    const { viewMode } = useViewModeStore.getState();
    expect(viewMode).toBe('list');
  });

  it('toggles between grid and list', () => {
    const { setViewMode } = useViewModeStore.getState();

    setViewMode('list');
    expect(useViewModeStore.getState().viewMode).toBe('list');

    setViewMode('grid');
    expect(useViewModeStore.getState().viewMode).toBe('grid');
  });
});
