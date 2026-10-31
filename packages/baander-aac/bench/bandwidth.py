#!/usr/bin/env python3
"""Audio bandwidth of the aligned decodes: the lowest frequency above
4 kHz from which the decode's long-term spectrum stays, on the median
over the next 1 kHz, more than 15 dB under the source's (the corpus
median per configuration; 24 kHz when it never does).  Shows each encoder's lowpass
choice, which explains part of the quality differences."""
import json
import os
import sys
from pathlib import Path

import numpy as np
import soundfile as sf
from scipy import signal

WORK = Path(os.environ.get("BENCH_WORK", Path(__file__).resolve().parent / "work"))


def ltas(x):
    f, p = signal.welch(x.mean(axis=1), fs=48000, nperseg=4096)
    return f, 10 * np.log10(p + 1e-20)


def bandwidth(ref, deg):
    f, pr = ltas(ref)
    _, pd = ltas(deg)
    step = f[1] - f[0]
    win = int(round(1000 / step))
    drop = pr - pd
    for i in np.nonzero(f >= 4000)[0]:
        if i + win > len(f):
            break
        if np.median(drop[i:i + win]) > 15:
            return float(f[i])
    return 24000.0


def main():
    pcm = WORK / "pcm"
    out = {}
    for d in sorted((WORK / "quality").iterdir()):
        bws = []
        for j in sorted(d.glob("*.json")):
            item = j.stem
            ref, _ = sf.read(pcm / f"{item}.wav", always_2d=True)
            deg, _ = sf.read(d / f"{item}.aligned.wav", always_2d=True)
            bws.append(bandwidth(ref, deg))
        if bws:
            out[d.name] = dict(median_hz=float(np.median(bws)), min_hz=float(min(bws)))
            print(f"{d.name:28s} {np.median(bws) / 1000:6.1f} kHz (min {min(bws) / 1000:.1f})")
    (WORK / "bandwidth.json").write_text(json.dumps(out, indent=1))


if __name__ == "__main__":
    sys.exit(main())
