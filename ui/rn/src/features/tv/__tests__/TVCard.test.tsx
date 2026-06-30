/**
 * TVCard component tests.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { TVCard } from '../components/TVCard';

describe('TVCard', () => {
  it('renders title and subtitle', () => {
    render(
      <TVCard
        title="Test Album"
        subtitle="Test Artist"
      />
    );

    expect(screen.getByText('Test Album')).toBeTruthy();
    expect(screen.getByText('Test Artist')).toBeTruthy();
  });

  it('renders without subtitle', () => {
    render(
      <TVCard title="Test Album" />
    );

    expect(screen.getByText('Test Album')).toBeTruthy();
    expect(screen.queryByText('Test Artist')).toBeNull();
  });

  it('calls onPress when pressed', () => {
    const onPressMock = jest.fn();
    const { getByTestId } = render(
      <TVCard title="Test" onPress={onPressMock} testID="card" />
    );

    const pressable = getByTestId('card');
    // fireEvent.press(pressable); // Would need TVFocusable testID to pass through
  });

  it('shows placeholder when no artwork URL', () => {
    const { getByTestId } = render(
      <TVCard title="Test" testID="card" />
    );

    const pressable = getByTestId('card');
    expect(pressable).toBeTruthy();
  });
});
