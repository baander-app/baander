import { test, expect } from './audio-graph-harness'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'

for (const [width, left, right] of [[0, 0.5, 0.5], [1, 1, 0], [2, 1.5, -0.5]]) {
  test(`stereo width ${width} renders the mid/side matrix`, async ({ render }) => {
    const result = await render({ chain: ['stereo', 'masterGain'], width })
    expect(result.leftGain).toBeCloseTo(left, 3)
    expect(result.rightGain).toBeCloseTo(right, 3)
  })
}

for (const [mode, left, right] of [['mid', 0.5, 0.5], ['side', 0.5, -0.5]] as const) {
  test(`${mode} monitoring renders only the selected component`, async ({ render }) => {
    const result = await render({ chain: ['stereo'], width: 1, mode })
    expect(result.leftGain).toBeCloseTo(left, 3)
    expect(result.rightGain).toBeCloseTo(right, 3)
  })
}

test('an omitted EQ module does not alter the rendered signal', async ({ render }) => {
  const result = await render({ chain: ['masterGain'], eqBoost: true })
  expect(result.leftGain).toBeCloseTo(1, 3)
})

for (const chain of [['stereo'], ['crossfeed'], ['stereo', 'crossfeed']]) {
  test(`one-channel media plays in both channels through ${chain.join(' and ')}`, async ({ render }) => {
    const result = await render({ chain, monoSource: true, width: 1, crossfeed: 0 })
    expect(result.leftGain).toBeCloseTo(1, 3)
    expect(result.rightGain).toBeCloseTo(1, 3)
  })
}

for (const [signal, mode, width, left, right] of [
  ['right', 'normal', 2, -0.5, 1.5], ['mono', 'side', 1, 0, 0],
  ['antiphase', 'mid', 1, 0, 0], ['antiphase', 'normal', 2, 2, -2],
] as const) {
  test(`${signal} input respects ${mode} monitoring at width ${width}`, async ({ render }) => {
    const result = await render({ chain: ['stereo'], signal, mode, width })
    expect(result.leftGain).toBeCloseTo(left, 3)
    expect(result.rightGain).toBeCloseTo(right, 3)
    if (left === 0 && right === 0) {
      expect(Math.max(...result.left.map(Math.abs), ...result.right.map(Math.abs))).toBeLessThan(0.0001)
    }
  })
}

test('stereo and crossfeed remain independently selectable stages', async ({ render }) => {
  const stereo = await render({ chain: ['stereo'], width: 0, crossfeed: 0.5 })
  expect(stereo.leftGain).toBeCloseTo(0.5, 3)
  expect(stereo.rightGain).toBeCloseTo(0.5, 3)
  const crossfeed = await render({ chain: ['crossfeed'], width: 0, crossfeed: 0.5 })
  expect(crossfeed.leftGain).toBeCloseTo(1, 3)
  expect(crossfeed.rightGain).toBeCloseTo(0.5, 3)
  const both = await render({ chain: ['stereo', 'crossfeed'], width: 0, crossfeed: 0.5 })
  expect(both.leftGain).toBeCloseTo(0.75, 3)
  expect(both.rightGain).toBeCloseTo(0.75, 3)
})

test('compressor before EQ renders the requested order', async ({ render }) => {
  const chain = ['compressor', 'eq', 'masterGain']
  const actual = await render({ chain, eqBoost: true })
  const expected = await render({ chain, eqBoost: true, reference: true })
  const reverse = await render({ chain: ['eq', 'compressor'], eqBoost: true, reference: true })
  const difference = (left: number[], right: number[]) => Math.sqrt(left.reduce((sum, value, index) => sum + (value - right[index]) ** 2, 0) / left.length)
  expect(difference(actual.left, expected.left)).toBeLessThan(0.00001)
  expect(difference(expected.left, reverse.left)).toBeGreaterThan(0.01)
})



test('repeated rebuilds remove obsolete stereo and crossfeed paths', async ({ render }) => {
  const result = await render({ chain: ['stereo', 'crossfeed'], width: 2, crossfeed: 0.5,
    rebuilds: [['crossfeed', 'stereo'], ['stereo'], ['crossfeed'], ['stereo', 'crossfeed'], ['masterGain']] })
  expect(result.leftGain).toBeCloseTo(1, 3)
  expect(result.rightGain).toBeCloseTo(0, 3)
  const active = await render({ chain: ['stereo', 'crossfeed'], width: 2, crossfeed: 0.5,
    rebuilds: [['crossfeed'], ['stereo'], ['stereo', 'crossfeed'], ['crossfeed', 'stereo'], ['stereo', 'crossfeed']] })
  expect(active.leftGain).toBeCloseTo(1.25, 3)
  expect(active.rightGain).toBeCloseTo(0.25, 3)
})

if (process.env.AUDIO_GRAPH_CDP_PORT) {
  test('agent-browser executes the actual offline renderer over CDP', async ({ render }) => {
    // Initial render loads the fixture before agent-browser selects the browser tab.
    await render({ chain: ['masterGain'] })
    const result = await promisify(execFile)(process.env.AGENT_BROWSER_BINARY ?? 'agent-browser', ['--cdp', process.env.AUDIO_GRAPH_CDP_PORT!,
      'eval', `(async()=>{const {leftGain,rightGain}=await window.audioGraphFixture.render({chain:['stereo'],width:0});return {leftGain,rightGain}})()`], { timeout: 20_000 })
    const signal = JSON.parse(result.stdout) as { leftGain: number; rightGain: number }
    expect(signal.leftGain).toBeCloseTo(0.5, 3)
    expect(signal.rightGain).toBeCloseTo(0.5, 3)
    console.log('agent-browser native rendered signal:', result.stdout.trim())
  })
}


test('native spectrum readiness connects analysis and closes both worklet ports', async ({ lifecycle }) => {
  const result = await lifecycle('ready-cleanup')
  expect(result.nativeNodes).toBe(true)
  expect(result.ready).toBe(true)
  expect(result.spectrumConnected).toBe(true)
  expect(result.sinkConnected).toBe(true)
  expect(result.closedPorts).toBe(2)
  expect(result.handlersCleared).toBe(true)
  expect(result.lateNodes).toBe(false)
  expect(result.fallbackActive).toBe(false)
})

test('passive mode stops native fallback analysis and keeps measurements neutral while playing', async ({ passiveAnalysis }) => {
  const result = await passiveAnalysis()
  expect(result.activeFallback).toBe(true)
  expect(result.activeSignal).toBe(true)
  expect(result.passive).toBe(true)
  expect(result.playing).toBe(true)
  expect(result.elapsed).toBeGreaterThan(0.2)
  expect(result.passiveFallback).toEqual([false, false, false])
  expect(result.readings).toEqual(Array(3).fill({
    phase: null,
    frequencySilent: true,
    timeDomainSilent: true,
    leftChannel: 0,
    rightChannel: 0,
    lufs: -60,
    peakFrequency: 0,
    spectralCentroid: 0,
    spectralRolloff: 0,
    spectralFlux: 0,
    spectralFlatness: 0,
    rms: 0,
  }))
})

for (const stop of ['disconnect', 'destroy'] as const) {
  test(`native addModule completion cannot recreate analysis after ${stop}`, async ({ lifecycle }) => {
    const result = await lifecycle(stop)
    expect(result.moduleCalls).toBe(1)
    expect(result.lateNodes).toBe(false)
    expect(result.ready).toBe(false)
    expect(result.fallbackActive).toBe(false)
  })
}
