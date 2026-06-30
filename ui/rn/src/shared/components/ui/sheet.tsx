import React, { useCallback } from 'react';
import { Modal, View, Pressable, StyleSheet, type ViewStyle } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

interface SheetProps {
  open: boolean;
  onClose: () => void;
  children: React.ReactNode;
  style?: ViewStyle;
}

export function Sheet({ open, onClose, children, style }: SheetProps) {
  return (
    <Modal visible={open} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      <View style={[styles.sheet, style]}>{children}</View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(0, 0, 0, 0.5)',
  },
  sheet: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: colors.sidebar,
    borderTopLeftRadius: radii.xl,
    borderTopRightRadius: radii.xl,
    padding: spacing[4],
    maxHeight: '80%',
  },
});
