import * as React from 'react';
import styled from 'styled-components';

const TableContainer = styled.div`
  position: relative;
  width: 100%;
  overflow-x: auto;
`;

const StyledTable = styled.table`
  width: 100%;
  caption-side: bottom;
  font-size: 0.875rem;
`;

function Table({ ...props }: React.ComponentProps<'table'>) {
  return (
    <TableContainer data-slot="table-container">
      <StyledTable data-slot="table" {...props} />
    </TableContainer>
  );
}

const StyledTableHeader = styled.thead`
  & tr { border-bottom: 1px solid var(--color-border); }
`;

function TableHeader({ ...props }: React.ComponentProps<'thead'>) {
  return <StyledTableHeader data-slot="table-header" {...props} />;
}

const StyledTableBody = styled.tbody`
  & tr:last-child { border-bottom: none; }
`;

function TableBody({ ...props }: React.ComponentProps<'tbody'>) {
  return <StyledTableBody data-slot="table-body" {...props} />;
}

const StyledTableFooter = styled.tfoot`
  border-top: 1px solid var(--color-border);
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  font-weight: 500;
  & > tr:last-child { border-bottom: none; }
`;

function TableFooter({ ...props }: React.ComponentProps<'tfoot'>) {
  return <StyledTableFooter data-slot="table-footer" {...props} />;
}

const StyledTableRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  transition: background-color var(--duration-hover) ease-out;
  &:hover { background-color: color-mix(in srgb, var(--color-muted) 50%, transparent); }
  &:has([aria-expanded]) { background-color: color-mix(in srgb, var(--color-muted) 50%, transparent); }
  &[data-state="selected"] { background-color: var(--color-muted); }
`;

function TableRow({ ...props }: React.ComponentProps<'tr'>) {
  return <StyledTableRow data-slot="table-row" {...props} />;
}

const StyledTableHead = styled.th`
  height: 2.5rem;
  padding: 0 0.5rem;
  text-align: left;
  vertical-align: middle;
  font-weight: 500;
  white-space: nowrap;
  color: var(--color-foreground);
  &:has([role="checkbox"]) { padding-right: 0; }
`;

function TableHead({ ...props }: React.ComponentProps<'th'>) {
  return <StyledTableHead data-slot="table-head" {...props} />;
}

const StyledTableCell = styled.td`
  padding: 0.5rem;
  vertical-align: middle;
  white-space: nowrap;
  &:has([role="checkbox"]) { padding-right: 0; }
`;

function TableCell({ ...props }: React.ComponentProps<'td'>) {
  return <StyledTableCell data-slot="table-cell" {...props} />;
}

const StyledTableCaption = styled.caption`
  margin-top: 1rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`;

function TableCaption({ ...props }: React.ComponentProps<'caption'>) {
  return <StyledTableCaption data-slot="table-caption" {...props} />;
}

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
};
