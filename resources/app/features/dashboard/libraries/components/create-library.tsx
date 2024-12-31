import React from 'react';
import { FloatingLabelInput } from '@/components/forms/floating-label-input/floating-label-input.tsx';
import { Select } from '@mantine/core';
import { getLibraryTypesForSelect } from '@/services/libraries/support.ts';


export function CreateLibrary() {
  const libraryTypes = getLibraryTypesForSelect();

  return (
    <form>
      <FloatingLabelInput label="Library name"/>

      <Select
        label="Type"
        data={libraryTypes}
      />

      <FloatingLabelInput label="Path" />
    </form>
  );
}