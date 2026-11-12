/**
 * Names this document to the playback session. A tab identity survives a reload, so a
 * registration that carries only the tab cannot tell a new page from a live one that registers
 * again. The page identity can: it is created once per page load, lives only in this module's
 * memory, and is never written to storage, so a reloaded or duplicated page gets another one
 * while a page restored from the back/forward cache keeps its own.
 */
export const playbackPageId: string = crypto.randomUUID()
