import React, { createContext, useContext, useState } from 'react';
import { Toast } from '@/ui/toast/toast';

interface ToastMessage {
  id: string;
  title: string;
  content: string;
  duration?: number;
  type?: 'foreground' | 'background';
}

interface ToastContextType {
  showToast: (toast: Omit<ToastMessage, 'id'>) => void;
}

const ToastContext = createContext<ToastContextType>({
  showToast: () => {
  },
});

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<ToastMessage[]>([]);

  const showToast = (toast: Omit<ToastMessage, 'id'>) => {
    const id = Math.random().toString(36).slice(2, 9);
    setToasts((prev) => [...prev, { ...toast, id }]);
  };

  const removeToast = (id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  };

  return (
    <ToastContext.Provider value={{ showToast }}>
      {children}

      {toasts.map((toast) => (
        <Toast
          key={toast.id}
          title={toast.title}
          content={toast.content}
          duration={toast.duration || 5000}
          type={toast.type || 'foreground'}
          onOpenChange={(open) => {
            if (!open) {
              removeToast(toast.id);
            }
          }}
        />
      ))}
    </ToastContext.Provider>
  );
};

export const useToast = () => useContext(ToastContext);