/** Installs the qualification observer against an explicit browser or deterministic test environment. */
export function installFrameObserver(environment = globalThis) {
  const video = environment.document.querySelector('#video')
  const { VideoFrame, performance, setTimeout, clearTimeout } = environment
  const observer = environment.sparseFrameObserver = {
    errors: [], droppedErrors: 0, constructed: 0, closed: 0, pending: 0, maxPending: 0, callbacks: 0, stopped: false,
  }
  const ownedFrames = new Set()
  const pendingCopies = new Set()
  const completed = new Map()
  let nextPublished = 0
  let callbackId
  const stop = () => {
    observer.stopped = true
    if (callbackId !== undefined) video.cancelVideoFrameCallback(callbackId)
  }
  const fail = (phase, error, sequence, diagnostic) => {
    stop()
    if (observer.errors.length < 64) observer.errors.push({ phase, sequence, message: String(error).slice(0, 1024),
      ...(diagnostic ? { diagnostic: { ...diagnostic, stages: { ...diagnostic.stages } } } : {}) })
    else observer.droppedErrors++
  }
  const closeOwned = frame => {
    if (!ownedFrames.delete(frame)) return
    try { frame.close(); observer.closed++ } catch (error) { fail('close', error) }
  }
  environment.sparseStopFrameObserver = async () => {
    stop()
    let timeout
    try {
      await Promise.race([
        Promise.all([...pendingCopies]),
        new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Native frame capture did not drain within two seconds')), 2000) }),
      ])
    } catch (error) { fail('drain', error) } finally {
      clearTimeout(timeout)
      for (const frame of ownedFrames) closeOwned(frame)
    }
    return { ...observer }
  }
  const observe = (at, metadata) => {
    if (observer.stopped) return
    callbackId = video.requestVideoFrameCallback(observe)
    const sequence = observer.callbacks++
    const entryAt = performance.now()
    const entryClock = video.currentTime
    const diagnostic = { sequence, at,
      callback: Object.fromEntries(['mediaTime', 'presentedFrames', 'presentationTime', 'expectedDisplayTime',
        'width', 'height', 'processingDuration', 'captureTime', 'receiveTime', 'rtpTimestamp']
        .map(key => [key, metadata[key] ?? null])),
      native: null,
      stages: { entryAt, entryClock, afterConstructAt: null, afterConstructClock: null,
        copyStartedAt: null, afterCopyCallAt: null, completedAt: null } }
    let frame
    let phase = 'constructor'
    try {
      if (ownedFrames.size >= 4) throw new Error('Native frame capture exceeded four outstanding snapshots')
      frame = new VideoFrame(video)
      ownedFrames.add(frame)
      observer.constructed++
      const afterConstructAt = performance.now()
      const afterConstructClock = video.currentTime
      diagnostic.stages.afterConstructAt = afterConstructAt
      diagnostic.stages.afterConstructClock = afterConstructClock
      const visibleRect = frame.visibleRect
      diagnostic.native = { timestamp: frame.timestamp, snapshotTime: frame.timestamp / 1000000,
        duration: frame.duration ?? null, format: frame.format ?? null,
        codedWidth: frame.codedWidth, codedHeight: frame.codedHeight,
        displayWidth: frame.displayWidth ?? null, displayHeight: frame.displayHeight ?? null,
        visibleRect: visibleRect ? { x: visibleRect.x, y: visibleRect.y, width: visibleRect.width, height: visibleRect.height } : null }
      phase = 'timestamp'
      const snapshotTime = frame.timestamp / 1000000
      if (!Number.isFinite(snapshotTime) || Math.abs(snapshotTime - metadata.mediaTime) > 0.000001) {
        throw new Error(`Native snapshot timestamp ${snapshotTime} does not match callback ${metadata.mediaTime}`)
      }
      phase = 'crop'
      const visible = frame.visibleRect
      if (!visible || ![visible.x, visible.y, visible.width, visible.height, frame.codedWidth, frame.codedHeight].every(Number.isInteger)
        || visible.width < 2 || visible.height < 2) throw new Error('Native snapshot has invalid visible dimensions')
      const rect = { x: Math.floor((visible.x + visible.width / 2) / 2) * 2,
        y: Math.floor((visible.y + visible.height / 2) / 2) * 2, width: 2, height: 2 }
      if (rect.x < visible.x || rect.y < visible.y || rect.x + 2 > visible.x + visible.width
        || rect.y + 2 > visible.y + visible.height || rect.x < 0 || rect.y < 0
        || rect.x + 2 > frame.codedWidth || rect.y + 2 > frame.codedHeight) throw new Error('Native snapshot crop exceeds its frame')
      const options = { format: 'RGBA', colorSpace: 'srgb', rect }
      if (frame.allocationSize(options) !== 16) throw new Error('Native snapshot crop must occupy sixteen bytes')
      const pixels = new Uint8Array(16)
      const record = { sequence, at, time: metadata.mediaTime, clock: entryClock, snapshotTime,
        presentedFrames: metadata.presentedFrames, presentationTime: metadata.presentationTime,
        expectedDisplayTime: metadata.expectedDisplayTime,
        stages: { entryAt, entryClock, afterConstructAt, afterConstructClock } }
      observer.pending++
      observer.maxPending = Math.max(observer.pending, observer.maxPending)
      let task
      task = (async () => {
        try {
          record.stages.copyStartedAt = performance.now()
          diagnostic.stages.copyStartedAt = record.stages.copyStartedAt
          const copying = frame.copyTo(pixels, options)
          record.stages.afterCopyCallAt = performance.now()
          diagnostic.stages.afterCopyCallAt = record.stages.afterCopyCallAt
          const layout = await copying
          record.stages.completedAt = performance.now()
          diagnostic.stages.completedAt = record.stages.completedAt
          if (layout.length !== 1 || layout[0].offset !== 0 || layout[0].stride !== 8 || pixels.byteLength !== 16) {
            throw new Error('Native snapshot returned an unexpected RGBA plane layout')
          }
          record.rgb = [...pixels.slice(0, 3)]
          completed.set(sequence, record)
          while (completed.has(nextPublished)) {
            environment.sparseColorFrames.push(completed.get(nextPublished))
            completed.delete(nextPublished++)
          }
        } catch (error) { fail('copy', error, sequence, diagnostic) } finally { closeOwned(frame) }
      })().finally(() => { pendingCopies.delete(task); observer.pending-- })
      pendingCopies.add(task)
    } catch (error) {
      fail(phase, error, sequence, diagnostic)
      if (frame) closeOwned(frame)
    }
  }
  callbackId = video.requestVideoFrameCallback(observe)
}
