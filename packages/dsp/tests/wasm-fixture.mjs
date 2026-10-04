import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export async function loadWasm(name) {
  const directory = process.env.DSP_WASM_DIR ?? fileURLToPath(new URL('../../../public/dsp/', import.meta.url))
  const bytes = await readFile(resolve(directory, `${name}.wasm`))
  const { instance } = await WebAssembly.instantiate(bytes, {})
  instance.exports._initialize?.()
  return instance.exports
}
