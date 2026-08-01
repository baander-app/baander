import React, { useCallback } from 'react';
import { Modal, View, Pressable, Text, FlatList, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface ContextMenuItem {
  label: string;
  onPress: () => void;
  destructive?: boolean;
}

interface ContextMenuProps {
  open: boolean;
  onClose: () => void;
  items: ContextMenuItem[];
  x?: number;
  y?: number;
}

export function ContextMenu({ open, onClose, items, x = 0, y = 0 }: ContextMenuProps) {
  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      <View style={[styles.menu, { top: y, left: x }]}>
        {items.map((item, i) => (
          <Pressable
            key={i}
            style={styles.item}
            onPress={() => {
              item.onPress();
              onClose();
            }}
          >
            <Text style={[styles.itemText, item.destructive && styles.destructive]}>
              {item.label}
            </Text>
          </Pressable>
        ))}
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    ...StyleSheet.absoluteFillObject,
  },
  menu: {
    position: 'absolute',
    backgroundColor: colors.sidebar,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingVertical: spacing[1],
    minWidth: 160,
    shadowOpacity: 0.2,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
    elevation: 8,
  },
  item: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[2],
  },
  itemText: {
    color: colors.foreground,
    fontSize: fontSizes.body,
  },
  destructive: {
    color: colors.destructive,
  },
});
