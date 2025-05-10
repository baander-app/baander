type MockDataGenerator<T> = {
  [K in keyof T]: T[K] extends string
                  ? string
                  : T[K] extends number
                    ? number
                    : T[K] extends boolean
                      ? boolean
                      : T[K] extends Array<infer U>
                        ? U extends object
                          ? MockDataGenerator<U>[] // Handle arrays of objects
                          : U[] // Handle arrays of primitives (e.g., string[], number[], etc.)
                        : T[K] extends object
                          ? MockDataGenerator<T[K]>
                          : any; // Fallback for other types
};

export function generateMockDataFromType<T>(type: T): MockDataGenerator<T> {
  const mockData: any = {};

  for (const key in type) {
    if (typeof type[key] === 'string') {
      mockData[key] = `Test ${key}`;
    } else if (typeof type[key] === 'number') {
      mockData[key] = 0;
    } else if (typeof type[key] === 'boolean') {
      mockData[key] = false;
    } else if (Array.isArray(type[key])) {
      // Handle arrays of primitives (e.g., string[], number[], etc.)
      const arrayType = (type[key] as any)[0]; // Get the type of the array elements
      if (typeof arrayType === 'string') {
        mockData[key] = [`Test ${key}`]; // Generate a single test string for string arrays
      } else if (typeof arrayType === 'number') {
        mockData[key] = [0]; // Generate a single test number for number arrays
      } else if (typeof arrayType === 'boolean') {
        mockData[key] = [false]; // Generate a single test boolean for boolean arrays
      } else if (typeof arrayType === 'object') {
        mockData[key] = [generateMockDataFromType(arrayType)]; // Handle arrays of objects
      } else {
        mockData[key] = []; // Fallback for unknown types
      }
    } else if (typeof type[key] === 'object') {
      mockData[key] = generateMockDataFromType(type[key]);
    } else {
      mockData[key] = null; // Fallback for other types
    }
  }

  return mockData;
}