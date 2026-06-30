import React from 'react';
import { Modal, View, Pressable, StyleSheet } from 'react-native';
import { colors } from '@/shared/theme/colors';
import { spacing, radii } from '@/shared/theme/tokens';

interface DialogProps {
  open: boolean;
  onClose: () => void;
  title?: string;
  children: React.ReactNode;
}

export function Dialog({ open, onClose, title, children }: DialogProps) {
  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={onClose}>
      <View style={styles.overlay}>
        <View style={styles.content}>
          {title && (
            <View style={styles.header}>
              <Dialog.Title>{title}</Dialog.Title>
            </View>
          )}
          {children}
        </View>
      </View>
    </Modal>
  );
}

function DialogTitle({ children }: { children: React.ReactNode }) {
  return <Dialog.Text style={styles.title}>{children}</Dialog.Text>;
}

function DialogText({ children, style }: { children: React.ReactNode; style?: any }) {
  const { Text } = require('react-native');
  return <Text style={[styles.text, style]}>{children}</Text>;
}

function DialogClose({ onClose, children }: { onClose: () => void; children: React.ReactNode }) {
  const { Text } = require('react-native');
  return (
    <Pressable onPress={onClose} style={styles.closeButton}>
      {typeof children === 'string' ? <Text style={styles.closeText}>{children}</Text> : children}
    </Pressable>
  );
}

Dialog.Title = DialogTitle;
Dialog.Text = DialogText;
Dialog.Close = DialogClose;

export { DialogTitle, DialogText, DialogClose };

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.5)',
    justifyContent: 'center',
    alignItems: 'center',
    padding: spacing[6],
  },
  content: {
    backgroundColor: colors.sidebar,
    borderRadius: radii.lg,
    padding: spacing[6],
    width: '100%',
    maxWidth: 480,
  },
  header: {
    marginBottom: spacing[4],
  },
  title: {
    color: colors.foreground,
    fontSize: 18,
    fontWeight: '600',
  },
  text: {
    color: colors.muted,
    fontSize: 14,
  },
  closeButton: {
    marginTop: spacing[4],
    alignSelf: 'flex-end',
  },
  closeText: {
    color: colors.primary,
    fontSize: 14,
    fontWeight: '500',
  },
});
