import React, { CSSProperties, useContext } from 'react';

const CategoryContext = React.createContext<{
  categoryClassName?: string | undefined;
  categoryStyle?: CSSProperties | undefined;
}>({});
export const useCategoryContext = () => useContext(CategoryContext);

interface CategoryProps {
  style?: CSSProperties | undefined;
  className?: string | undefined;
  name: string;
  children: React.ReactNode;
}
export const Category: React.FC<CategoryProps> = ({
                                                    children,
                                                    name,
                                                    className,
                                                    style,
                                                  }) => {
  return (
    <CategoryContext.Provider value={{categoryClassName: className, categoryStyle: style}}>
      {getTransformedCategoryChildren({
        children,
        categoryName: name,
      })}
    </CategoryContext.Provider>
  );
};

interface GetTransformedCategoryChildrenParams {
  children: React.ReactNode;
  categoryName: string;
}

function getTransformedCategoryChildren({
                                          children,
                                          categoryName,
                                        }: GetTransformedCategoryChildrenParams) {
  return React.Children.map(children, (child) => {
    if (React.isValidElement<{ categoryName?: string }>(child)) {
      return React.cloneElement(child, {categoryName});
    }
    return child;
  });
}
