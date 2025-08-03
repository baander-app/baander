export interface PaginatedResponse<T = unknown> {
  data: T[];
  currentPage: number;
  lastPage: number;
  nextPage?: number;
  total: number;
  count: number;
  limit: number;
}

/**
 * Higher-order function approach - wraps the hook call itself
 */
export function withPagination<T extends (...args: any[]) => any>(
  hook: T
): (...args: Parameters<T>) => ReturnType<T> {
  return (...args: Parameters<T>) => {
    // Get the options (last parameter that's an object with query property)
    const lastArg = args[args.length - 1];
    const hasOptions = lastArg && typeof lastArg === 'object' && 'query' in lastArg;

    if (hasOptions) {
      const enhancedArgs = [...args];
      const optionsIndex = enhancedArgs.length - 1;
      const options = enhancedArgs[optionsIndex] as any;

      enhancedArgs[optionsIndex] = {
        ...options,
        query: {
          ...options.query,
          getNextPageParam: (lastPage: any) => {
            return lastPage.nextPage && lastPage.currentPage < lastPage.lastPage
                   ? lastPage.nextPage
                   : undefined;
          },
          getPreviousPageParam: (firstPage: any) => {
            return firstPage.currentPage > 1
                   ? firstPage.currentPage - 1
                   : undefined;
          },
        },
      };

      return hook(...enhancedArgs);
    }

    // If no options, add them
    const newOptions = {
      query: {
        getNextPageParam: (lastPage: any) => {
          return lastPage.nextPage && lastPage.currentPage < lastPage.lastPage
                 ? lastPage.nextPage
                 : undefined;
        },
        getPreviousPageParam: (firstPage: any) => {
          return firstPage.currentPage > 1
                 ? firstPage.currentPage - 1
                 : undefined;
        },
      },
    };

    return hook(...args, newOptions);
  };
}

/**
 * Simple wrapper that adds pagination to hook calls
 */
export function addPagination<T extends Record<string, any>>(
  options: T = {} as T
): T & {
  query: {
    getNextPageParam: (lastPage: any) => number | undefined;
    getPreviousPageParam: (firstPage: any) => number | undefined;
  };
} {
  return {
    ...options,
    query: {
      ...options.query,
      getNextPageParam: (lastPage: any) => {
        return lastPage.nextPage && lastPage.currentPage < lastPage.lastPage
               ? lastPage.nextPage
               : undefined;
      },
      getPreviousPageParam: (firstPage: any) => {
        return firstPage.currentPage > 1
               ? firstPage.currentPage - 1
               : undefined;
      },
    },
  } as any;
}