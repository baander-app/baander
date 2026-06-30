/**
 * TVContentRow component tests.
 */

import React from 'react';
import { render, screen } from '@testing-library/react-native';
import { TVContentRow } from '../components/TVContentRow';

describe('TVContentRow', () => {
  it('renders section title', () => {
    render(
      <TVContentRow title="Featured Albums">
        <></>
      </TVContentRow>
    );

    expect(screen.getByText('Featured Albums')).toBeTruthy();
  });

  it('renders children', () => {
    const TestChild = () => <test-child data-testid="child">Child</test-child>;

    render(
      <TVContentRow title="Test">
        <TestChild />
      </TVContentRow>
    );

    expect(screen.getByTestId('child')).toBeTruthy();
  });

  it('renders view all button when provided', () => {
    const onViewAllMock = jest.fn();

    render(
      <TVContentRow title="Test" onViewAll={onViewAllMock}>
        <></>
      </TVContentRow>
    );

    expect(screen.getByText('View all')).toBeTruthy();
  });

  it('renders custom view all text', () => {
    render(
      <TVContentRow title="Test" onViewAll={jest.fn()} viewAllText="See more">
        <></>
      </TVContentRow>
    );

    expect(screen.getByText('See more')).toBeTruthy();
  });
});
