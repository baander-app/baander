import { LibraryResource, LibraryType } from '@/app/api-client/requests';

export class Library implements Partial<LibraryResource> {
  name!: string;
  slug!: string;
  path!: string;
  type!: LibraryType;
  order!: number;
  lastScan!: string | null;
  createdAt!: string | null;
  updatedAt!: string | null;

  constructor(props: Partial<LibraryResource>) {
    Object.assign(this, props);
  }
}