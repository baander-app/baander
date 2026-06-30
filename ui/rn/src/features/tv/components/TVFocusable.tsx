/**
 * TVFocusable - Wrapper component for D-pad focus management on Apple TV.
 *
 * Uses TVFocusGuideView from react-native-tvos to enable:
 * - autoFocus: automatically receives focus on first visit
 * - trapFocus: prevents focus escaping in a direction
 * - destinations: directs focus to specific elements
 *
 * Provides clear visual feedback for the focused element:
 * - 3px white border with glow effect
 * - Scale animation on focus
 */

import React, { useState } from 'react';
import {
  Pressable,
  View,
  StyleSheet,
  GestureResponderEvent,
  ViewStyle,
} from 'react-native';
import { TVFocusGuideView } from 'react-native-tvos';
import { tvColors, tvSizes, tvFontSizes } from '../theme/tv-tokens';

export interface TVFocusableProps {
  children: React.ReactNode;
  isFocused?: boolean;
  onFocus?: () => void;
  onBlur?: () => void;
  onPress?: (event: GestureResponderEvent) => void;
  style?: ViewStyle;
  contentStyle?: ViewStyle;
  // TVFocusGuideView props
  autoFocus?: boolean;
  trapFocusUp?: boolean;
  trapFocusDown?: boolean;
  trapFocusLeft?: boolean;
  trapFocusRight?: boolean;
}

export function TVFocusable({
  children,
  isFocused: controlledFocus,
  onFocus,
  onBlur,
  onPress,
  style,
  contentStyle,
  autoFocus,
  trapFocusUp,
  trapFocusDown,
  trapFocusLeft,
  trapFocusRight,
}: TVFocusableProps) {
  const [internalFocused, setInternalFocused] = useState(false);

  const isFocused = controlledFocus !== undefined ? controlledFocus : internalFocused;

  const handleFocus = () => {
    if (!isFocused) {
      setInternalFocused(true);
      onFocus?.();
    }
  };

  const handleBlur = () => {
    if (isFocused) {
      setInternalFocused(false);
      onBlur?.();
    }
  };

  const handlePress = (event: GestureResponderEvent) => {
    onPress?.(event);
  };

  return (
    <TVFocusGuideView
      autoFocus={autoFocus}
      trapFocusUp={trapFocusUp}
      trapFocusDown={trapFocusDown}
      trapFocusLeft={trapFocusLeft}
      trapFocusRight={trapFocusRight}
      style={styles.container}
    >
      <Pressable
        onPress={handlePress}
        onFocus={handleFocus}
        onBlur={handleBlur}
        style={[
          styles.pressable,
          isFocused && styles.focused,
          style,
        ]}
      >
        <View
          style={[
            styles.content,
            isFocused && styles.contentFocused,
            contentStyle,
          ]}
        >
          {children}
        </View>
      </Pressable>
    </TVFocusGuideView>
  );
}

const styles = StyleSheet.create({
  container: {
    // Container for TVFocusGuideView
  },
  pressable: {
    // Base pressable style
  },
  focused: {
    // Focus indicator - 3px white border with shadow
    borderWidth: tvSizes.focusBorderWidth,
    borderColor: tvColors.focusBorder,
    borderRadius: 8,
    shadowColor: tvColors.focusBorder,
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.3,
    shadowRadius: tvSizes.focusGlow,
    elevation: 4, // Android
  },
  content: {
    // Content wrapper
  },
  contentFocused: {
    // Subtle scale on focus for visual feedback
    transform: [{ scale: 1.02 }],
  },
});
