import { useMemo, useState } from 'react';
import {
  MantineReactTable,
  MRT_ColumnDef,
  MRT_ColumnFilterFnsState,
  MRT_ColumnFiltersState,
  MRT_PaginationState,
  MRT_SortingState, useMantineReactTable,
} from 'mantine-react-table';
import { UserResource } from '@/api-client/requests';
import { useUserServiceUsersIndex } from '@/api-client/queries';
import { stringify } from '@/utils/json.ts';
import { ActionIcon, Tooltip } from '@mantine/core';
import { Iconify } from '@/components/icons/iconify.tsx';

export function UserTable() {
  const columns = useMemo<MRT_ColumnDef<UserResource>[]>(
    () => [
      {
        accessorKey: 'name',
        header: 'Name',
      },
      {
        accessorKey: 'email',
        header: 'Email',
      },
      {
        accessorKey: 'isAdmin',
        header: 'Is Admin',
      },
      {
        accessorKey: 'createdAt',
        header: 'Created at',
      },
      {
        accessorKey: 'updatedAt',
        header: 'Updated at',
      },
    ],
    [],
  );

  const [columnFilters, setColumnFilters] = useState<MRT_ColumnFiltersState>(
    [],
  );
  const [columnFilterFns, setColumnFilterFns] = //filter modes
    useState<MRT_ColumnFilterFnsState>(
      Object.fromEntries(
        columns.map(({ accessorKey }) => [accessorKey, 'contains']),
      ),
    );
  const [globalFilter, setGlobalFilter] = useState('');
  const [sorting, setSorting] = useState<MRT_SortingState>([]);
  const [pagination, setPagination] = useState<MRT_PaginationState>({
    pageIndex: 0,
    pageSize: 30,
  });

  const { data, isError, isFetching, isLoading, refetch } = useUserServiceUsersIndex({
    sorting: stringify(sorting),
    filters: stringify(columnFilters),
    filterModes: stringify(columnFilterFns),
    page: pagination.pageIndex,
    limit: pagination.pageSize,
  });

  const fetchedUsers = data?.data ?? [];
  const totalRowCount = data?.total ?? 0;

  const table = useMantineReactTable({
    columns,
    data: fetchedUsers,
    enableColumnFilterModes: true,
    columnFilterModeOptions: ['contains', 'startsWith', 'endsWith'],
    initialState: { showColumnFilters: true },
    manualFiltering: true,
    manualPagination: true,
    manualSorting: true,
    mantineToolbarAlertBannerProps:
      isError
      ? { color: 'red', children: 'Error loading data' }
      : undefined,
    onColumnFilterFnsChange: setColumnFilterFns,
    onColumnFiltersChange: setColumnFilters,
    onGlobalFilterChange: setGlobalFilter,
    onPaginationChange: setPagination,
    onSortingChange: setSorting,
    renderTopToolbarCustomActions: () => (
      <Tooltip label="Refresh Data">
        <ActionIcon
          variant="transparent"
          radius="lg"
          onClick={() => refetch()}
          size="md"
          loading={isLoading}
        >
          <Iconify icon="eva:refresh-fill" fontSize={36} />
        </ActionIcon>
      </Tooltip>
    ),
    rowCount: totalRowCount,
    state: {
      columnFilterFns,
      columnFilters,
      globalFilter,
      isLoading,
      pagination,
      showAlertBanner: isError,
      showProgressBars: isFetching,
      sorting,
    },
    mantineTableProps: {
      withTableBorder: false,
    },
    mantinePaperProps: {
      shadow: 'none',
      withBorder: false,
      radius: 'md'
    },
  });

  return (
    <MantineReactTable
      table={table}
    />
  );
}