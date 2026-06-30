/**
 * CreatePlaylistPage -- form for creating a new playlist.
 *
 * Fields: name, description, isPublic toggle. Save + Cancel.
 */

import React, { useState } from 'react';
import {
  View, Text, TextInput, Switch, Pressable, ActivityIndicator, StyleSheet,
} from 'react-native';
import { createPlaylist } from '../api/playlist-api';
import { colors } from '@/shared/theme/colors';
import { spacing, radii, fontSizes } from '@/shared/theme/tokens';

interface CreatePlaylistPageProps {
  onSave: (playlistPublicId: string) => void;
  onCancel: () => void;
}

export function CreatePlaylistPage({ onSave, onCancel }: CreatePlaylistPageProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [isPublic, setIsPublic] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async () => {
    if (!name.trim()) {
      setError('Playlist name is required');
      return;
    }

    setIsSaving(true);
    setError(null);

    try {
      const playlist = await createPlaylist({
        name: name.trim(),
        description: description.trim() || undefined,
        isPublic,
      });
      onSave(playlist.publicId);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create playlist');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Create Playlist</Text>

      <View style={styles.field}>
        <Text style={styles.label}>Name</Text>
        <TextInput
          style={styles.input}
          value={name}
          onChangeText={setName}
          placeholder="Playlist name"
          placeholderTextColor={colors.muted}
          maxLength={100}
          autoFocus
        />
      </View>

      <View style={styles.field}>
        <Text style={styles.label}>Description</Text>
        <TextInput
          style={[styles.input, styles.textArea]}
          value={description}
          onChangeText={setDescription}
          placeholder="Optional description"
          placeholderTextColor={colors.muted}
          multiline
          numberOfLines={3}
          maxLength={500}
        />
      </View>

      <View style={styles.toggleRow}>
        <Text style={styles.label}>Public</Text>
        <Switch
          value={isPublic}
          onValueChange={setIsPublic}
          trackColor={{ false: colors.border, true: colors.primary }}
          thumbColor={colors.foreground}
        />
      </View>

      {error && <Text style={styles.errorText}>{error}</Text>}

      <View style={styles.actions}>
        <Pressable style={styles.cancelButton} onPress={onCancel}>
          <Text style={styles.cancelText}>Cancel</Text>
        </Pressable>
        <Pressable
          style={[styles.saveButton, isSaving && styles.saveButtonDisabled]}
          onPress={handleSave}
          disabled={isSaving}
        >
          {isSaving ? (
            <ActivityIndicator size="small" color={colors.foreground} />
          ) : (
            <Text style={styles.saveText}>Create</Text>
          )}
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1, backgroundColor: colors.background,
    padding: spacing[4], paddingTop: spacing[8],
  },
  title: { color: colors.foreground, fontSize: fontSizes['2xl'], fontWeight: '700', marginBottom: spacing[6] },
  field: { marginBottom: spacing[4] },
  label: { color: colors.muted, fontSize: fontSizes.sm, marginBottom: spacing[1] },
  input: {
    backgroundColor: colors.card, borderWidth: 1, borderColor: colors.border,
    borderRadius: radii.md, paddingHorizontal: spacing[3], paddingVertical: spacing[2],
    color: colors.foreground, fontSize: fontSizes.body,
  },
  textArea: { minHeight: 80, textAlignVertical: 'top' },
  toggleRow: {
    flexDirection: 'row', alignItems: 'center',
    justifyContent: 'space-between', marginBottom: spacing[4],
  },
  errorText: { color: colors.destructive, fontSize: fontSizes.sm, marginBottom: spacing[4] },
  actions: { flexDirection: 'row', gap: spacing[3], marginTop: spacing[4] },
  cancelButton: {
    flex: 1, paddingVertical: spacing[3], borderRadius: radii.md,
    borderWidth: 1, borderColor: colors.border, alignItems: 'center',
  },
  cancelText: { color: colors.foreground, fontSize: fontSizes.body },
  saveButton: {
    flex: 1, paddingVertical: spacing[3], borderRadius: radii.md,
    backgroundColor: colors.primary, alignItems: 'center',
  },
  saveButtonDisabled: { opacity: 0.6 },
  saveText: { color: colors.foreground, fontSize: fontSizes.body, fontWeight: '600' },
});
