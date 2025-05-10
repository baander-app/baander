import { ColumnDef, getCoreRowModel, getSortedRowModel, SortingState, useReactTable } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { useUserServiceGetApiUsers } from '@/api-client/queries';
import { stringify } from '@/utils/json.ts';
import { UserResource } from '@/api-client/requests';
import { Tooltip } from 'radix-ui';
import { Button, Flex, Text } from '@radix-ui/themes';
import  { Table } from '@radix-ui/themes';

export function UserTable() {
  const columns = useMemo<ColumnDef<UserResource>[]>(
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

  const [sorting, setSorting] = useState<SortingState>([]);
  const [pagination, setPagination] = useState({
    pageIndex: 0,
    pageSize: 30,
  });

  const { data, isLoading, refetch } = useUserServiceGetApiUsers({
    sorting: stringify(sorting),
    page: pagination.pageIndex,
    limit: pagination.pageSize,
  });

  const fetchedUsers = data?.data ?? [];

  const table = useReactTable({
    data: fetchedUsers,
    rowCount: data?.meta?.total,
    columns,
    state: {
      sorting,
      pagination,
    },
    manualSorting: true,
    manualPagination: true,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    onSortingChange: setSorting,
    onPaginationChange: setPagination,
  });


  return (
    <>
      <Flex justify="end" mb="2">
        <Tooltip.Root>
          <Tooltip.Trigger asChild>
            <Button onClick={() => refetch()} disabled={isLoading}>
              <Iconify icon="eva:refresh-fill"/>
            </Button>
          </Tooltip.Trigger>
          <Tooltip.Portal>
            <Tooltip.Content>
              Refresh
            </Tooltip.Content>
          </Tooltip.Portal>
        </Tooltip.Root>
      </Flex>

      <Table.Root>
        <Table.Header>
          <Table.Row>
            {table.getHeaderGroups()[0]?.headers.map((header, index) => (
              <Table.ColumnHeaderCell key={index}>
                {header.isPlaceholder ? null : (
                  <Flex 
                    style={{ cursor: header.column.getCanSort() ? 'pointer' : 'default' }}
                    onClick={header.column.getToggleSortingHandler()}
                  >
                    {header.column.columnDef.header instanceof Function
                      ? header.column.columnDef.header(header.getContext())
                      : header.column.columnDef.header}
                    {header.column.getIsSorted() === 'asc' 
                      ? ' 🔼' 
                      : header.column.getIsSorted() === 'desc' 
                        ? ' 🔽' 
                        : ''}
                  </Flex>
                )}
              </Table.ColumnHeaderCell>
            ))}
          </Table.Row>
        </Table.Header>

        <Table.Body>
          {table.getRowModel().rows.map((row, rowIndex) => (
            <Table.Row key={rowIndex}>
              {row.getVisibleCells().map((cell, cellIndex) => (
                cellIndex === 0 ? (
                  <Table.RowHeaderCell key={cellIndex}>
                    {cell.column.columnDef.cell instanceof Function
                      ? cell.column.columnDef.cell(cell.getContext())
                      : cell.getValue()}
                  </Table.RowHeaderCell>
                ) : (
                  <Table.Cell key={cellIndex}>
                    {cell.column.columnDef.cell instanceof Function
                      ? cell.column.columnDef.cell(cell.getContext())
                      : cell.getValue()}
                  </Table.Cell>
                )
              ))}
            </Table.Row>
          ))}
        </Table.Body>
      </Table.Root>

      <Flex justify="between" align="center" mt="4">
        <Button 
          onClick={() => table.previousPage()}
          disabled={!table.getCanPreviousPage()}
        >
          Previous
        </Button>
        <Text>
          Page {table.getState().pagination.pageIndex + 1} of{' '}
          {table.getPageCount()}
        </Text>
        <Button
          onClick={() => table.nextPage()}
          disabled={!table.getCanNextPage()}
        >
          Next
        </Button>
      </Flex>
    </>
  );
}
