/**
 * useAlbums hook tests.
 */

import { renderHook, waitFor } from '@testing-library/react';
import { useAlbums } from '../hooks/useAlbums';
import * as catalogApi from '../api/catalog-api';

// Mock the API
jest.mock('../api/catalog-api');

describe('useAlbums', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('initially returns empty data and loading state', () => {
    (catalogApi.getAlbums as jest.Mock).mockImplementation(() => new Promise(() => {}));

    const { result } = renderHook(() => useAlbums());

    expect(result.current.data).toEqual([]);
    expect(result.current.isLoading).toBe(true);
    expect(result.current.error).toBeNull();
  });

  it('fetches albums successfully', async () => {
    const mockAlbums = [
      { uuid: '1', publicId: 'album-1', title: 'Album 1', artistName: 'Artist 1', coverImageBlurhash: null, releaseYear: 2024, songCount: 10, duration: 3000 },
      { uuid: '2', publicId: 'album-2', title: 'Album 2', artistName: 'Artist 2', coverImageBlurhash: null, releaseYear: 2024, songCount: 8, duration: 2400 },
    ];
    (catalogApi.getAlbums as jest.Mock).mockResolvedValue(mockAlbums);

    const { result } = renderHook(() => useAlbums());

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });

    expect(result.current.data).toEqual(mockAlbums);
    expect(result.current.error).toBeNull();
  });

  it('handles fetch errors', async () => {
    const mockError = new Error('Network error');
    (catalogApi.getAlbums as jest.Mock).mockRejectedValue(mockError);

    const { result } = renderHook(() => useAlbums());

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });

    expect(result.current.data).toEqual([]);
    expect(result.current.error).toEqual(mockError);
  });

  it('refetches data', async () => {
    let callCount = 0;
    (catalogApi.getAlbums as jest.Mock).mockImplementation(() => {
      callCount++;
      return Promise.resolve([
        { uuid: '1', publicId: 'album-1', title: `Album ${callCount}`, artistName: 'Artist 1', coverImageBlurhash: null, releaseYear: 2024, songCount: 10, duration: 3000 },
      ]);
    });

    const { result } = renderHook(() => useAlbums());

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });

    expect(callCount).toBe(1);
    expect(result.current.data[0].title).toBe('Album 1');

    result.current.refetch();

    await waitFor(() => {
      expect(callCount).toBe(2);
    });

    expect(result.current.data[0].title).toBe('Album 2');
  });
});
