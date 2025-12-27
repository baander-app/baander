import { Action, combineSlices, configureStore, ThunkAction } from '@reduxjs/toolkit';
import { persistReducer, persistStore } from 'redux-persist';
import storage from 'redux-persist/lib/storage';
import { notificationsSlice } from '@/app/store/notifications/notifications-slice.ts';
import { eventBridgeMiddleware } from '@/app/store/middleware/event-bridge.middleware.ts';

const rootReducer = combineSlices(
  notificationsSlice,
);
export type RootState = ReturnType<typeof rootReducer>

const persistConfig = {
  key: 'root',
  storage,
};
const persistedReducer = persistReducer(persistConfig, rootReducer);// as unknown as typeof rootReducer;

export const makeStore = () => {
  return configureStore({
    devTools: true,
    reducer: persistedReducer,
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware({
        serializableCheck: false,
      }).concat(eventBridgeMiddleware)
  });
};

export const store = makeStore();

export const persistor = persistStore(store);

// Infer the type of `store`
export type AppStore = typeof store
// Infer the `AppDispatch` type from the store itself
export type AppDispatch = AppStore["dispatch"]
export type AppThunk<ThunkReturnType = void> = ThunkAction<
  ThunkReturnType,
  RootState,
  unknown,
  Action
>