/**
 * TVHomePage component tests.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { NavigationContainer } from '@react-navigation/native';
import { TVHomePage } from '../pages/TVHomePage';

// Mock hooks
jest.mock('@/features/catalog/hooks/useAlbums', () => ({
  useAlbums: () => ({
    data: [],
    isLoading: false,
    error: null,
    refetch: jest.fn(),
  }),
}));

describe('TVHomePage', () => {
  it('renders without crashing', () => {
    render(
      <NavigationContainer>
        <TVHomePage />
      </NavigationContainer>
    );

    // Hero section should be present
    expect(screen.getByText('Featured Album')).toBeTruthy();
    expect(screen.getByText('Play')).toBeTruthy();
  });

  it('does not render empty sections', () => {
    // Mock returns empty arrays
    const { useAlbums } = require('@/features/catalog/hooks/useAlbums');
    useAlbums.mockReturnValue({
      data: [],
      isLoading: false,
      error: null,
      refetch: jest.fn(),
    });

    const { queryByText } = render(
      <NavigationContainer>
        <TVHomePage />
      </NavigationContainer>
    );

    // "Featured" and "Recently Added" sections should not render when empty
    expect(queryByText('Featured')).toBeNull();
  });

  it('renders content sections when data is available', () => {
    const { useAlbums } = require('@/features/catalog/hooks/useAlbums');
    useAlbums.mockReturnValue({
      data: [
        {
          uuid: '1',
          publicId: 'album-1',
          title: 'Test Album',
          artistName: 'Test Artist',
          coverImageBlurhash: null,
          releaseYear: 2024,
          songCount: 10,
          duration: 3000,
        },
      ],
      isLoading: false,
      error: null,
      refetch: jest.fn(),
    });

    const { getByText } = render(
      <NavigationContainer>
        <TVHomePage />
      </NavigationContainer>
    );

    expect(getByText('Test Album')).toBeTruthy();
  });
});
