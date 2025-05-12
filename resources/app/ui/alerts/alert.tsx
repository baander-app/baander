import React, { useState, ReactNode } from 'react';

interface AlertProps {
  color?: string;
  children?: ReactNode;
  showDismissButton?: boolean;
}

interface AlertTitleProps {
  children: ReactNode;
}

interface AlertDescriptionProps {
  children: ReactNode;
}

interface AlertActionProps {
  children: ReactNode;
  onClick: () => void;
}

const Alert = ({ children, showDismissButton = true }: AlertProps) => {
  const [isVisible, setIsVisible] = useState(true);

  // Check if any `Alert.Action` components are provided
  const hasActions = React.Children.toArray(children).some(
    (child) => React.isValidElement(child) && child.type === Alert.Action
  );

  if (!isVisible) return null;

  return (
    <div className="p-4 border rounded-lg bg-gray-50 shadow-sm">
      {children}
      {/* Add a default dismiss button if no actions are provided */}
      {!hasActions || showDismissButton && (
        <div className="mt-4 flex justify-end">
          <button
            onClick={() => setIsVisible(false)}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300"
          >
            Dismiss
          </button>
        </div>
      )}
    </div>
  );
};

const AlertTitle = ({ children }: AlertTitleProps) => {
  return <h3 className="text-lg font-semibold text-gray-900">{children}</h3>;
};

const AlertDescription = ({ children }: AlertDescriptionProps) => {
  return <p className="mt-2 text-sm text-gray-600">{children}</p>;
};

const AlertAction = ({ children, onClick }: AlertActionProps) => {
  return (
    <button
      onClick={onClick}
      className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700"
    >
      {children}
    </button>
  );
};

// Attach subcomponents to the main Alert component
Alert.Title = AlertTitle;
Alert.Description = AlertDescription;
Alert.Action = AlertAction;

export default Alert;