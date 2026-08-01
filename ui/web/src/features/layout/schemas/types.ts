import type { SidebarItemData } from '../stores/sidebar-store'

export interface SidebarSection {
  id: string
  label: string
  type: 'navigation' | 'recent'
  items: SidebarItemData[]
}

export interface MediaSidebarSchema {
  mediaType: string
  sections: SidebarSection[]
}
