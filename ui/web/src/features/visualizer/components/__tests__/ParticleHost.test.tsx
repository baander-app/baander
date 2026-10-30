import { StrictMode } from 'react'
import { cleanup, render, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { VisualizerHost } from '../VisualizerHost'
import { ParticleRenderer } from '../../renderers/particle'
import { rendererRegistry } from '../../renderer-registry'
import type { VisualizerRenderer } from '../../types'

const { created, disposed } = vi.hoisted(() => ({ created: vi.fn(), disposed: vi.fn() }))

vi.mock('../../hooks/use-visualizer-loop', () => ({ useVisualizerLoop: vi.fn() }))
vi.mock('three', () => ({
  Scene: class { add() {} },
  PerspectiveCamera: class {
    position = { z: 0 }
    updateProjectionMatrix() {}
  },
  BufferGeometry: class {
    setAttribute() {}
    dispose() {}
  },
  BufferAttribute: class {},
  Points: class {
    material: { dispose: () => void }
    constructor(_geometry: unknown, material: { dispose: () => void }) { this.material = material }
  },
  PointsMaterial: class { dispose() {} },
  NormalBlending: 1,
  WebGLRenderer: class {
    constructor() { created() }
    setPixelRatio() {}
    setSize() {}
    setClearColor() {}
    dispose() { disposed() }
  },
}))

const dimensions = { width: 320, height: 160, compact: false, smoothingAlpha: 0.35 }

describe('particle canvas ownership', () => {
  beforeEach(() => {
    created.mockClear()
    disposed.mockClear()
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 320, height: 160 } as DOMRect)
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('cancels the obsolete StrictMode initialization before it creates or restores a canvas', async () => {
    vi.spyOn(rendererRegistry, 'has').mockReturnValue(true)
    const old = new ParticleRenderer()
    const ownCanvas = document.createElement('canvas')
    const current: VisualizerRenderer = {
      id: 'particles', isWebGL: true,
      init: (canvas) => {
        canvas.style.display = 'none'
        canvas.parentElement!.appendChild(ownCanvas)
      },
      render: vi.fn(), resize: vi.fn(), destroy: vi.fn(() => ownCanvas.remove()),
    }
    vi.spyOn(rendererRegistry, 'create').mockReturnValueOnce(old).mockReturnValueOnce(current)
    const destroy = vi.spyOn(old, 'destroy')
    const { container, unmount } = render(<StrictMode><VisualizerHost mode="particles" /></StrictMode>)
    await waitFor(() => expect(destroy).toHaveBeenCalledTimes(1))
    const canvases = container.querySelectorAll('canvas')
    expect(canvases).toHaveLength(2)
    expect(canvases[0]).toHaveStyle({ display: 'none' })
    expect(canvases[1]).not.toHaveStyle({ display: 'none' })
    expect(created).not.toHaveBeenCalled()
    unmount()
    expect(destroy).toHaveBeenCalledTimes(1)
    expect(current.destroy).toHaveBeenCalledTimes(1)
    expect(disposed).not.toHaveBeenCalled()
  })

  it('never restores a host canvas now owned by another particle renderer', async () => {
    const container = document.createElement('div')
    const host = document.createElement('canvas')
    container.appendChild(host)
    const old = new ParticleRenderer()
    const current = new ParticleRenderer()
    await old.init(host, dimensions)
    await current.init(host, dimensions)
    old.destroy()
    expect(host.style.display).toBe('none')
    expect(container.querySelectorAll('canvas')).toHaveLength(2)
    current.destroy()
    expect(host.style.display).toBe('')
    expect(container.querySelectorAll('canvas')).toHaveLength(1)
    expect(disposed).toHaveBeenCalledTimes(2)
  })
})
