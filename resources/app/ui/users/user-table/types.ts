// Custom types to replace @tanstack/react-table types

import React from 'react';

// Basic type definitions
export type SortingState = Array<{id: string, desc: boolean}>;

export interface ColumnDef<T> {
  header: string | React.ReactNode | ((context: any) => React.ReactNode);
  accessorKey?: string;
  accessorFn?: (row: T) => any;
  cell?: (info: { row: { original: T }, getValue: () => any }) => React.ReactNode;
  size?: number;
}

// Pagination state
export interface PaginationState {
  pageIndex: number;
  pageSize: number;
}

// Table related interfaces
export interface Table<T> {
  getHeaderGroups: () => HeaderGroup<T>[];
  getRowModel: () => RowModel<T>;
  previousPage: () => void;
  nextPage: () => void;
  getCanPreviousPage: () => boolean;
  getCanNextPage: () => boolean;
  getPageCount: () => number;
  getState: () => { pagination: PaginationState };
}

export interface HeaderGroup<T> {
  id: string;
  headers: Header<T>[];
}

export interface Header<T> {
  id: string;
  colSpan: number;
  isPlaceholder: boolean;
  column: Column<T>;
  getSize: () => number;
  getContext: () => any;
}

export interface Column<T> {
  columnDef: ColumnDef<T>;
  getCanSort: () => boolean;
  getToggleSortingHandler: () => (e: React.MouseEvent) => void;
  getIsSorted: () => string | boolean;
}

export interface RowModel<T> {
  rows: Row<T>[];
}

export interface Row<T> {
  id: string;
  original: T;
  getVisibleCells: () => Cell<T>[];
}

export interface Cell<T> {
  id: string;
  column: Column<T>;
  getContext: () => any;
  getValue: () => any;
}

// Utility functions
export function getCoreRowModel<T>() {
  return function(table: any): RowModel<T> {
    // This would normally process the data to create rows
    // For now, we'll return a simplified implementation
    if (!table || !table.data) {
      return { rows: [] };
    }

    return {
      rows: (table.data || []).map((item: T, index: number) => ({
        id: `row-${index}`,
        original: item,
        getVisibleCells: () => 
          (table.columns || []).map((column: Column<T>, colIndex: number) => ({
            id: `cell-${index}-${colIndex}`,
            column,
            getContext: () => ({ row: { original: item }, getValue: () => getValueForCell(item, column) }),
            getValue: () => getValueForCell(item, column)
          }))
      }))
    };
  };
}

export function getSortedRowModel<T>() {
  return function(table: any): RowModel<T> {
    // This would normally sort the rows based on sorting state
    // For now, we'll return the core row model
    if (!table) {
      return { rows: [] };
    }
    return getCoreRowModel<T>()(table);
  };
}

export function useReactTable<T>(options: {
  data: T[];
  rowCount?: number;
  columns: ColumnDef<T>[];
  state: { 
    sorting: SortingState;
    pagination: PaginationState;
  };
  manualSorting?: boolean;
  manualPagination?: boolean;
  onSortingChange: (updater: any) => void;
  onPaginationChange: (updater: any) => void;
  getCoreRowModel: () => (table: any) => RowModel<T>;
  getSortedRowModel: () => (table: any) => RowModel<T>;
  debugAll?: boolean;
  debugColumns?: boolean;
}): Table<T> {
  // Create a simplified table implementation
  const table = {
    data: options.data,
    rowCount: options.rowCount || options.data.length,
    columns: options.columns.map((columnDef, index) => ({
      id: `column-${index}`,
      columnDef,
      getCanSort: () => true,
      getToggleSortingHandler: () => () => {
        const id = columnDef.accessorKey || `column-${index}`;
        const currentSort = options.state.sorting.find(s => s.id === id);
        if (!currentSort) {
          options.onSortingChange([...options.state.sorting, { id, desc: false }]);
        } else if (!currentSort.desc) {
          options.onSortingChange(
            options.state.sorting.map(s => s.id === id ? { ...s, desc: true } : s)
          );
        } else {
          options.onSortingChange(options.state.sorting.filter(s => s.id !== id));
        }
      },
      getIsSorted: () => {
        const id = columnDef.accessorKey || `column-${index}`;
        const currentSort = options.state.sorting.find(s => s.id === id);
        if (!currentSort) return false;
        return currentSort.desc ? 'desc' : 'asc';
      },
      getSize: () => columnDef.size || 100
    })),
    getHeaderGroups: () => [{
      id: 'headerGroup',
      headers: options.columns.map((columnDef, index) => ({
        id: `header-${index}`,
        colSpan: 1,
        isPlaceholder: false,
        column: {
          columnDef,
          getCanSort: () => true,
          getToggleSortingHandler: () => () => {
            const id = columnDef.accessorKey || `column-${index}`;
            const currentSort = options.state.sorting.find(s => s.id === id);
            if (!currentSort) {
              options.onSortingChange([...options.state.sorting, { id, desc: false }]);
            } else if (!currentSort.desc) {
              options.onSortingChange(
                options.state.sorting.map(s => s.id === id ? { ...s, desc: true } : s)
              );
            } else {
              options.onSortingChange(options.state.sorting.filter(s => s.id !== id));
            }
          },
          getIsSorted: () => {
            const id = columnDef.accessorKey || `column-${index}`;
            const currentSort = options.state.sorting.find(s => s.id === id);
            if (!currentSort) return false;
            return currentSort.desc ? 'desc' : 'asc';
          }
        },
        getSize: () => columnDef.size || 100,
        getContext: () => ({ column: { columnDef } })
      }))
    }],
    getRowModel: () => {
      try {
        return options.getSortedRowModel()(table) || { rows: [] };
      } catch (error) {
        console.error('Error in getRowModel:', error);
        return { rows: [] };
      }
    },
    previousPage: () => {
      const { pageIndex, pageSize } = options.state.pagination;
      if (pageIndex > 0) {
        options.onPaginationChange({
          ...options.state.pagination,
          pageIndex: pageIndex - 1
        });
      }
    },
    nextPage: () => {
      const { pageIndex, pageSize } = options.state.pagination;
      const pageCount = Math.ceil(table.rowCount / pageSize);
      if (pageIndex < pageCount - 1) {
        options.onPaginationChange({
          ...options.state.pagination,
          pageIndex: pageIndex + 1
        });
      }
    },
    getCanPreviousPage: () => options.state.pagination.pageIndex > 0,
    getCanNextPage: () => {
      const { pageIndex, pageSize } = options.state.pagination;
      const pageCount = Math.ceil(table.rowCount / pageSize);
      return pageIndex < pageCount - 1;
    },
    getPageCount: () => {
      const { pageSize } = options.state.pagination;
      return Math.ceil(table.rowCount / pageSize);
    },
    getState: () => ({
      pagination: options.state.pagination
    })
  };

  return table as Table<T>;
}

// Helper function to get value for a cell
function getValueForCell<T>(item: T, column: Column<T>): any {
  if (!item || !column || !column.columnDef) {
    return undefined;
  }

  const { accessorKey, accessorFn } = column.columnDef;

  if (accessorKey) {
    return (item as any)[accessorKey];
  }

  if (accessorFn) {
    try {
      return accessorFn(item);
    } catch (error) {
      console.error('Error in accessorFn:', error);
      return undefined;
    }
  }

  return undefined;
}
