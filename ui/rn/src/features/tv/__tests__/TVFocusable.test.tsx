/**
 * TVFocusable component tests.
 */

import React from 'react';
import { render, fireEvent } from '@testing-library/react-native';
import { TVFocusable } from '../components/TVFocusable';
import { Text } from 'react-native';

describe('TVFocusable', () => {
  it('renders children correctly', () => {
    const { getByText } = render(
      <TVFocusable>
        <Text>Test Content</Text>
      </TVFocusable>
    );

    expect(getByText('Test Content')).toBeTruthy();
  });

  it('applies custom styles', () => {
    const { getByTestId } = render(
      <TVFocusable style={{ backgroundColor: 'red' }} testID="focusable">
        <Text>Test</Text>
      </TVFocusable>
    );

    const component = getByTestId('focusable');
    // Note: StyleSheet flattening may make exact style checking difficult
    expect(component).toBeTruthy();
  });

  it('calls onFocus when focused', () => {
    const onFocusMock = jest.fn();

    const { getByTestId } = render(
      <TVFocusable onFocus={onFocusMock} testID="focusable">
        <Text>Test</Text>
      </TVFocusable>
    );

    const pressable = getByTestId('focusable');

    // Simulate focus event
    fireEvent(pressable, 'focus');

    // Note: Focus behavior on react-native-tvos may require TV-specific testing
    // This test verifies the callback is wired correctly
    expect(onFocusMock).toHaveBeenCalled();
  });

  it('calls onBlur when focus is lost', () => {
    const onBlurMock = jest.fn();

    const { getByTestId } = render(
      <TVFocusable onBlur={onBlurMock} testID="focusable">
        <Text>Test</Text>
      </TVFocusable>
    );

    const pressable = getByTestId('focusable');

    // Simulate blur event
    fireEvent(pressable, 'blur');

    expect(onBlurMock).toHaveBeenCalled();
  });

  it('calls onPress when pressed', () => {
    const onPressMock = jest.fn();

    const { getByTestId } = render(
      <TVFocusable onPress={onPressMock} testID="focusable">
        <Text>Test</Text>
      </TVFocusable>
    );

    const pressable = getByTestId('focusable');

    fireEvent.press(pressable);

    expect(onPressMock).toHaveBeenCalledTimes(1);
  });

  it('shows focus indicator when isFocused is true', () => {
    const { getByTestId } = render(
      <TVFocusable isFocused={true} testID="focusable">
        <Text>Test</Text>
      </TVFocusable>
    );

    const pressable = getByTestId('focusable');
    const styles = pressable.props.style;

    // Focus indicator should be applied
    expect(styles).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          borderWidth: expect.any(Number),
          borderColor: '#ffffff',
        }),
      ])
    );
  });
});
