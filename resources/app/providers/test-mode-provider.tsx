import React, { createContext, useContext, useState, useEffect } from 'react';
import { generateMockDataFromType } from '@/utils/testing/generateMockDataFromType.ts';

interface TestModeContextType {
  isTestMode: boolean;
  toggleTestMode: () => void;
  debugInfo: Record<string, any>;
  addDebugInfo: (key: string, value: any) => void;
}

const TestModeContext = createContext<TestModeContextType | undefined>(undefined);

export const TestModeProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [isTestMode, setIsTestMode] = useState(false);
  const [debugInfo, setDebugInfo] = useState<Record<string, any>>({});

  const toggleTestMode = () => {
    setIsTestMode((prev) => !prev);
  };

  const addDebugInfo = (key: string, value: any) => {
    setDebugInfo((prev) => ({ ...prev, [key]: value }));
  };

  useEffect(() => {
    if (isTestMode) {
      console.log('Debug Info:', debugInfo);
    }
  }, [debugInfo, isTestMode]);

  return (
    <TestModeContext.Provider value={{ isTestMode, toggleTestMode, debugInfo, addDebugInfo }}>
      {children}
    </TestModeContext.Provider>
  );
};

export const useTestMode = () => {
  const context = useContext(TestModeContext);
  if (!context) {
    throw new Error('useTestMode must be used within a TestModeProvider');
  }
  return context;
};



export function withTestMode<T extends { viewModel?: any }>(
  Component: React.ComponentType<T>,
  viewModelType: T['viewModel'],
) {
  return function TestModeWrapper(props: T) {
    const { isTestMode } = useTestMode();

    if (isTestMode) {
      // Generate mock data for the viewModel using the explicitly passed type
      const mockViewModel = generateMockDataFromType(viewModelType);

      // Override the viewModel with mock data
      const testProps = {
        ...props,
        viewModel: mockViewModel,
      };

      return <Component {...testProps} />;
    }

    return <Component {...props} />;
  };
}
