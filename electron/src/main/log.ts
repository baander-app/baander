import log from 'electron-log/main';

log.initialize();

log.errorHandler.startCatching();

export const mainLog = log;
