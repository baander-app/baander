// Regenerate the two boundary goldens from the repository root:
// node packages/dsp/tests/reference/integrated-reference.mjs
// This offline reference imports no production implementation or WASM.
// ITU-R BS.1770-5 Annex 1, Tables 1/2 and equations (2), (4)–(7):
// https://www.itu.int/dms_pubrec/itu-r/rec/bs/R-REC-BS.1770-5-202311-I!!PDF-E.pdf
// EBU Tech 3341 §2.3: complete 400 ms blocks with 75% overlap.
// https://tech.ebu.ch/docs/tech/tech3341.pdf
const sampleRate = 48000;
const stages = [
  { b: [1.53512485958697, -2.69169618940638, 1.19839281085285], a: [1, -1.69065929318241, 0.73248077421585] },
  { b: [1, -2, 1], a: [1, -1.99004745483398, 0.99007225036621] },
];

for (const quiet of [-32.787, -32.790]) {
  const states = stages.map(() => ({ x: [0, 0], y: [0, 0] }));
  const prefixEnergy = new Float64Array(sampleRate * 40 + 1);
  for (let frame = 0; frame < sampleRate * 40; frame++) {
    const level = frame < sampleRate * 20 ? -20 : quiet;
    let input = Math.fround(10 ** (level / 20) * Math.sin(2 * Math.PI * 1000 * frame / sampleRate));
    // Direct form I retains explicit input/output delay samples; production
    // uses a different filter realization. Both channels carry identical PCM.
    for (let stage = 0; stage < stages.length; stage++) {
      const { b, a } = stages[stage];
      const state = states[stage];
      const output = b[0] * input + b[1] * state.x[0] + b[2] * state.x[1]
        - a[1] * state.y[0] - a[2] * state.y[1];
      state.x = [input, state.x[0]];
      state.y = [output, state.y[0]];
      input = output;
    }
    prefixEnergy[frame + 1] = prefixEnergy[frame] + 2 * input * input;
  }
  const window = sampleRate * 0.4;
  const hop = sampleRate * 0.1;
  const blocks = [];
  for (let start = 0; start + window < prefixEnergy.length; start += hop) {
    blocks.push((prefixEnergy[start + window] - prefixEnergy[start]) / window);
  }
  const absolute = blocks.filter(energy => -0.691 + 10 * Math.log10(energy) > -70);
  const mean = absolute.reduce((sum, energy) => sum + energy, 0) / absolute.length;
  const selected = absolute.filter(energy => energy > mean / 10);
  const integrated = -0.691 + 10 * Math.log10(
    selected.reduce((sum, energy) => sum + energy, 0) / selected.length);
  console.log(JSON.stringify({ quiet, integrated, selected: selected.length, blocks: blocks.length }));
}
