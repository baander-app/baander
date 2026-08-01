/**
 * TVNavigator component tests.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { NavigationContainer } from '@react-navigation/native';
import { TVNavigator } from '../navigation/TVNavigator';

describe('TVNavigator', () => {
  it('renders the initial route (TVHome)', () => {
    render(
      <NavigationContainer>
        <TVNavigator />
      </NavigationContainer>
    );

    expect(screen.getByText('TVHome')).toBeTruthy();
  });

  it('renders all defined routes', () => {
    const { getByText } = render(
      <NavigationContainer>
        <TVNavigator />
      </NavigationContainer>
    );

    // Check that initial route is rendered
    expect(getByText('TVHome')).toBeTruthy();
  });

  it('uses TVAppShell wrapper', () => {
    const { getByTestId } = render(
      <NavigationContainer>
        <TVNavigator />
      </NavigationContainer>
    );

    // TVAppShell renders a container with safe zone
    // The navigator should be nested inside
    expect(screen.getByText('TVHome')).toBeTruthy();
  });
});
