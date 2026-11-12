#!/usr/bin/env python3
"""Codec comparison harness for baander-aac.

Stages (each caches its results under the work directory, so a stage can
be re-run after a tool or a configuration changes by deleting its cache):

  quality  encode every corpus item with every configuration, decode it
           with the family's reference decoder, align it to the source
           and score it with PEAQ (GstPEAQ, basic and advanced) and, when
           a ViSQOL binary is configured, ViSQOL v3 (audio mode)
  speed    encode and decode a two-minute concatenation of the corpus
           with every encoder and decoder, single-threaded, best of N
  compat   decode our streams with every decoder and other encoders'
           streams with ours, and compare the decodes
  lossless bAAC's lossless mode against FLAC and ALAC on the same corpus
           and on held-out recordings: size, speed and bit-exactness,
           with the gates of the lossless mode (see LOSSLESS below)
  check    print every lossless gate of the cached run as passed, failed
           or undecided, and exit non-zero unless all of them passed
  report   write the CSV files and the Markdown tables under results/;
           only the sections and files whose stage has a cache change
  selftest check the harness's own logic on made-up figures

Tool paths come from environment variables (see TOOLS below); a missing
tool skips the configurations that need it and says so.
"""
import argparse
import csv
import datetime
import hashlib
import json
import math
import os
import platform
import re
import shlex
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
import wave
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np

try:
    import soundfile as sf
    from scipy import signal
except ImportError:  # the lossless, check and report stages do not need them
    sf = signal = None

HERE = Path(__file__).resolve().parent
WORK = Path(os.environ.get("BENCH_WORK", HERE / "work"))
RESULTS = Path(os.environ.get("BENCH_RESULTS", HERE / "results"))

# Every tool the harness starts runs in the C locale: flac reads the times
# given to --skip and --until with the locale's decimal separator, and
# refuses 5:00.00 where that separator is a comma.  The harness sets the
# locale in its own environment before it starts any tool, so every child
# inherits it and no timed command needs a wrapper.
os.environ["LC_ALL"] = "C"

TOOLS = {
    "baac": os.environ.get("BAAC", str(HERE.parent / "cmake-build" / "baac")),
    "ffmpeg": os.environ.get("FFMPEG", "ffmpeg"),
    "fdk_enc": os.environ.get("FDK_AAC_ENC", ""),       # fdk-aac aac-enc
    "fdk_dec": os.environ.get("FDK_AAC_DEC", ""),       # fdk-aac aac-dec
    "fdk_xhe": os.environ.get("FDK_XHE_ENC", ""),       # fdk-aac xhe-aac-enc
    "xaac_enc": os.environ.get("XAAC_ENC", ""),         # libxaac xaacenc
    "xaac_dec": os.environ.get("XAAC_DEC", ""),         # libxaac xaacdec
    "qaac": os.environ.get("QAAC", ""),                 # qaac64.exe, run with wine
    "refalac": os.environ.get("REFALAC", ""),           # refalac64.exe, run with wine
    "flac": os.environ.get("FLAC", "flac"),             # the pinned flac 1.4.3 (setup-refs.sh)
    "peaq": os.environ.get("PEAQ", ""),                 # GstPEAQ's peaq
    "visqol": os.environ.get("VISQOL", ""),             # ViSQOL v3 binary
    "visqol_model": os.environ.get("VISQOL_MODEL", ""),  # libsvm model file
    "wine": os.environ.get("WINE", "wine"),
}

MUSIC = ["strauss48_stereo", "sopr48_stereo", "contrabassoon48_stereo",
         "guitar48_stereo", "glock48_stereo", "castanets48_stereo",
         "harpsichord48_stereo", "steely48_stereo", "ravel48_stereo",
         "moonlight48_stereo"]
SPEECH = ["CA01_01"]


def have(tool):
    path = TOOLS[tool]
    if not path:
        return False
    if tool in ("qaac", "refalac"):
        return Path(path).is_file() and shutil.which(TOOLS["wine"]) is not None
    return shutil.which(path) is not None or Path(path).is_file()


def wine(tool):
    return [TOOLS["wine"], TOOLS[tool]]


# ---------------------------------------------------------------- configs
#
# Every configuration: id, family, encoder, nominal kbit/s, mode, output
# extension, the tools it needs and the encode command.  `src` is the
# 16-bit WAV, `f32` the same samples as raw float (our CLI's input).

# The -15 dB bandwidth of FDK's AAC-LC decodes on this corpus (bandwidth.py).
BW_FDK = {64: 12600, 96: 13800, 128: 15000}


def cfg_list():
    out = []

    def add(family, enc, kbps, mode, ext, needs, cmd, ch=2):
        cid = f"{family}-{enc}-{mode}{kbps}" if kbps else f"{family}-{enc}-{mode}"
        out.append(dict(id=cid, family=family, encoder=enc, kbps=kbps,
                        mode=mode, ext=ext, needs=needs, cmd=cmd, ch=ch))

    # AAC-LC, CBR (each encoder's constant-bitrate mode).
    for k in (64, 96, 128, 192, 256):
        b = k * 1000
        add("lc", "ours", k, "cbr", "aac", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "lc"])
        if k in BW_FDK:
            # Experiment: our encoder with the lowpass FDK's streams show.
            add("lc", "oursbw", k, "cbr", "aac", ["baac"],
                lambda s, f, o, b=b, k=k: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr",
                                           "0", "lc", "--bandwidth", str(BW_FDK[k])])
        add("lc", "oursnoab", k, "cbr", "aac", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "lc",
                                  "--no-afterburner"])
        add("lc", "ffmpeg", k, "cbr", "aac", ["ffmpeg"],
            lambda s, f, o, k=k: [TOOLS["ffmpeg"], "-v", "error", "-y", "-threads", "1", "-i", s,
                                  "-c:a", "aac", "-b:a", f"{k}k", "-f", "adts", o])
        add("lc", "fdk", k, "cbr", "aac", ["fdk_enc"],
            lambda s, f, o, b=b: [TOOLS["fdk_enc"], "-r", str(b), "-t", "2", s, o])
        if k in BW_FDK:
            # Experiment: FDK without its afterburner.
            add("lc", "fdknoab", k, "cbr", "aac", ["fdk_enc"],
                lambda s, f, o, b=b: [TOOLS["fdk_enc"], "-r", str(b), "-t", "2", "-a", "0", s, o])
        add("lc", "libxaac", k, "cbr", "aac", ["xaac_enc"],
            lambda s, f, o, b=b: [TOOLS["xaac_enc"], f"-ifile:{s}", f"-ofile:{o}", "-aot:2",
                                  f"-br:{b}", "-adts:1"])
        add("lc", "apple", k, "cbr", "aac", ["qaac"],
            lambda s, f, o, k=k: wine("qaac") + ["--silent", "--adts", "-c", str(k), "-o", o, s])

    # AAC-LC, each encoder's own VBR scale (bitrate follows the material).
    for m in (2, 3, 4, 5):
        add("lcvbr", "ours", m, "vbr", "aac", ["baac"],
            lambda s, f, o, m=m: [TOOLS["baac"], "enc", f, o, "48000", "2", "128000", "cbr", "0",
                                  "lc", "--vbr-mode", str(m)])
        add("lcvbr", "fdk", m, "vbr", "aac", ["fdk_enc"],
            lambda s, f, o, m=m: [TOOLS["fdk_enc"], "-t", "2", "-v", str(m), s, o])
    for q in (45, 63, 82, 100):
        add("lcvbr", "apple", q, "tvbr", "aac", ["qaac"],
            lambda s, f, o, q=q: wine("qaac") + ["--silent", "--adts", "-V", str(q), "-o", o, s])

    # HE-AAC v1.
    for k in (32, 48, 64):
        b = k * 1000
        add("he", "ours", k, "cbr", "aac", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "he"])
        add("he", "oursnoab", k, "cbr", "aac", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "he",
                                  "--no-afterburner"])
        add("he", "fdk", k, "cbr", "aac", ["fdk_enc"],
            lambda s, f, o, b=b: [TOOLS["fdk_enc"], "-r", str(b), "-t", "5", s, o])
        add("he", "libxaac", k, "cbr", "aac", ["xaac_enc"],
            lambda s, f, o, b=b: [TOOLS["xaac_enc"], f"-ifile:{s}", f"-ofile:{o}", "-aot:5",
                                  f"-br:{b}", "-adts:1"])
        add("he", "apple", k, "cbr", "aac", ["qaac"],
            lambda s, f, o, k=k: wine("qaac") + ["--silent", "--adts", "--he", "-c", str(k), "-o", o, s])

    # HE-AAC v2 (Apple's Windows encoder has no v2).
    for k in (24, 32):
        b = k * 1000
        add("hev2", "ours", k, "cbr", "aac", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "hev2"])
        add("hev2", "fdk", k, "cbr", "aac", ["fdk_enc"],
            lambda s, f, o, b=b: [TOOLS["fdk_enc"], "-r", str(b), "-t", "29", s, o])
        add("hev2", "libxaac", k, "cbr", "aac", ["xaac_enc"],
            lambda s, f, o, b=b: [TOOLS["xaac_enc"], f"-ifile:{s}", f"-ofile:{o}", "-aot:29",
                                  f"-br:{b}", "-adts:1"])

    # xHE-AAC (USAC).  Our encoder and FDK's write LOAS, libxaac a raw
    # elementary stream with a side file of access-unit sizes.
    for k in (24, 32, 48, 64, 96):
        b = k * 1000
        add("xhe", "ours", k, "cbr", "loas", ["baac"],
            lambda s, f, o, b=b: [TOOLS["baac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "xhe"])
        # FDK's two-pass mode: measure the loudness, then encode with it.
        # Its one-pass mode (-l) levels the programme to the given loudness
        # inside the encoder, which changes the signal under test.
        add("xhe", "fdk", k, "cbr", "loas", ["fdk_xhe"],
            lambda s, f, o, b=b: ["sh", "-c", '"$0" -e "$2.loud" "$1" "$2.tmp" >/dev/null && '
                                  '"$0" -r "$3" -i "$2.loud" "$1" "$2" && rm -f "$2.tmp" "$2.loud"',
                                  TOOLS["fdk_xhe"], s, o, str(b)])
        if k not in (64, 96):
            continue  # libxaac's USAC encoder resets any other bitrate to 96 kbit/s
        add("xhe", "libxaac", k, "cbr", "es", ["xaac_enc"],
            lambda s, f, o, b=b: [TOOLS["xaac_enc"], f"-ifile:{s}", f"-ofile:{o}", "-aot:42",
                                  f"-br:{b}"])
    return out


def decode_cmd(cfg, stream, wav):
    """The quality decode: FFmpeg for AAC-LC and HE-AAC, libxaac for USAC."""
    if cfg["family"] == "xhe":
        cmd = [TOOLS["xaac_dec"], f"-ifile:{stream}", f"-ofile:{wav}", "-pcmsz:16",
               "-peak_limiter_off:1", "-loudness_leveling:0"]
        if stream.endswith(".es"):
            cmd += [f"-imeta:{stream[:-3]}.txt", "-mp4:1"]
        return cmd
    return [TOOLS["ffmpeg"], "-v", "error", "-y", "-threads", "1", "-i", stream,
            "-c:a", "pcm_f32le", wav]


# ---------------------------------------------------------------- helpers

def run(cmd, timed=None, **kw):
    """Run cmd; with timed=path, record wall, user, sys seconds and max RSS."""
    if timed:
        cmd = ["/usr/bin/time", "-f", "%e %U %S %M", "-o", timed] + cmd
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kw)
    if p.returncode != 0:
        raise RuntimeError(f"{shlex.join(cmd)} failed ({p.returncode}): "
                           f"{p.stderr.decode(errors='replace')[-600:]}")
    return p


def read_time(path):
    wall, user, sys_, rss = Path(path).read_text().split()[-4:]
    return dict(wall=float(wall), cpu=float(user) + float(sys_), rss_kb=int(rss))


def load(path):
    x, sr = sf.read(path, dtype="float64", always_2d=True)
    return x, sr


def align(ref, deg, max_lead=4096, max_lag=24000):
    """Delay of deg against ref (cross-correlation of the mono mixes) and
    the gain that matches the levels below 3 kHz, where every codec under
    test codes the waveform."""
    r = ref.mean(axis=1)
    d = deg.mean(axis=1)
    n = min(len(r), len(d), 48000 * 8)
    c = signal.correlate(d[: n + max_lag], r[:n], mode="full", method="fft")
    lags = np.arange(-n + 1, len(d[: n + max_lag]))
    keep = (lags >= -max_lead) & (lags <= max_lag)
    lag = int(lags[keep][np.argmax(c[keep])])
    if lag >= 0:
        out = deg[lag:]
    else:
        out = np.vstack([np.zeros((-lag, deg.shape[1])), deg])
    if len(out) < len(ref):
        out = np.vstack([out, np.zeros((len(ref) - len(out), deg.shape[1]))])
    out = out[: len(ref)]
    if out.shape[1] != ref.shape[1]:
        out = np.repeat(out[:, :1], ref.shape[1], axis=1)
    sos = signal.butter(6, 3000, fs=48000, output="sos")
    rl = signal.sosfiltfilt(sos, ref.mean(axis=1))
    ol = signal.sosfiltfilt(sos, out.mean(axis=1))
    gain = float(np.dot(rl, ol) / max(np.dot(ol, ol), 1e-12))
    rho = float(np.dot(rl, ol) / max(np.sqrt(np.dot(rl, rl) * np.dot(ol, ol)), 1e-12))
    return out, lag, gain, rho


def write16(path, x):
    sf.write(path, np.clip(x, -1.0, 32767 / 32768), 48000, subtype="PCM_16")


def peaq(ref, deg, advanced=False):
    cmd = [TOOLS["peaq"]] + (["--advanced"] if advanced else []) + [ref, deg]
    p = run(cmd)
    m = re.search(r"Objective Difference Grade:\s*(-?[0-9.]+)", p.stdout.decode())
    return float(m.group(1))


# ---------------------------------------------------------------- stages

def prepare_corpus():
    corpus = WORK / "corpus"
    if not (corpus / f"{MUSIC[0]}.wav").exists():
        subprocess.run([str(HERE / "corpus.sh"), str(corpus)], check=True)
    # Every encoder reads the same samples: a canonical 44-byte-header
    # WAV (libxaac's reader takes nothing else) and raw float for ours.
    pcm = WORK / "pcm"
    pcm.mkdir(parents=True, exist_ok=True)
    for name in MUSIC + SPEECH:
        if not (pcm / f"{name}.f32").exists():
            x, sr = sf.read(corpus / f"{name}.wav", dtype="int16", always_2d=True)
            sf.write(pcm / f"{name}.wav", x, sr, subtype="PCM_16", format="WAV")
            (x.astype("<f4") / 32768.0).tofile(pcm / f"{name}.f32")
    return pcm


def quality_one(cfg, item, corpus):
    d = WORK / "quality" / cfg["id"]
    d.mkdir(parents=True, exist_ok=True)
    row_path = d / f"{item}.json"
    if row_path.exists():
        return json.loads(row_path.read_text())
    src = str(corpus / f"{item}.wav")
    f32 = str(corpus / f"{item}.f32")
    stream = str(d / f"{item}.{cfg['ext']}")
    dec = str(d / f"{item}.dec.wav")
    out = str(d / f"{item}.aligned.wav")
    run(cfg["cmd"](src, f32, stream), timed=str(d / f"{item}.enc.time"))
    run(decode_cmd(cfg, stream, dec))
    ref, _ = load(src)
    deg, sr = load(dec)
    if sr != 48000:
        raise RuntimeError(f"{cfg['id']} {item}: decoded at {sr} Hz")
    aligned, lag, gain, rho = align(ref, deg)
    write16(out, aligned * gain)
    secs = len(ref) / 48000
    size = os.path.getsize(stream)
    row = dict(config=cfg["id"], family=cfg["family"], encoder=cfg["encoder"],
               nominal=cfg["kbps"], mode=cfg["mode"], item=item, seconds=secs,
               bytes=size, kbps=size * 8 / secs / 1000, delay=lag,
               gain_db=20 * np.log10(max(gain, 1e-9)), lowband_corr=rho,
               **{f"enc_{k}": v for k, v in read_time(d / f"{item}.enc.time").items()})
    row["peaq_basic"] = peaq(src, out)
    row["peaq_adv"] = peaq(src, out, advanced=True)
    os.remove(dec)
    row_path.write_text(json.dumps(row))
    return row


def stage_quality(args):
    corpus = prepare_corpus()
    cfgs = [c for c in cfg_list() if re.search(args.only or ".", c["id"])]
    jobs = []
    for c in cfgs:
        missing = [t for t in c["needs"] + (["xaac_dec"] if c["family"] == "xhe" else [])
                   if not have(t)]
        if missing:
            print(f"skip {c['id']}: missing {', '.join(missing)}", file=sys.stderr)
            continue
        for item in MUSIC:
            jobs.append((c, item))
    errors = []
    with ThreadPoolExecutor(max_workers=args.jobs) as pool:
        futs = {pool.submit(quality_one, c, i, corpus): (c["id"], i) for c, i in jobs}
        for n, fut in enumerate(futs, 1):
            try:
                fut.result()
            except Exception as e:  # report every failure, keep going
                errors.append(f"{futs[fut]}: {e}")
            if n % 50 == 0:
                print(f"{n}/{len(jobs)}", file=sys.stderr)
    for e in errors:
        print("ERROR", e, file=sys.stderr)
    if have("visqol"):
        stage_visqol(cfgs, corpus, args.jobs)


def stage_visqol(cfgs, corpus, jobs=1):
    """ViSQOL v3 audio mode on every aligned decode, in parallel batches."""
    todo = []
    for c in cfgs:
        for item in MUSIC:
            row_path = WORK / "quality" / c["id"] / f"{item}.json"
            if not row_path.exists():
                continue
            row = json.loads(row_path.read_text())
            if "visqol" not in row:
                todo.append((row_path, str(corpus / f"{item}.wav"),
                             str(row_path.with_suffix("").with_suffix(".aligned.wav"))))
    if not todo:
        return

    def batch(part, tmp):
        inp = Path(tmp) / "batch.csv"
        res = Path(tmp) / "results.csv"
        with open(inp, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(["reference", "degraded"])
            for _, r, d in part:
                w.writerow([r, d])
        cmd = [TOOLS["visqol"], "--batch_input_csv", str(inp), "--results_csv", str(res)]
        if TOOLS["visqol_model"]:
            cmd += ["--similarity_to_quality_model", TOOLS["visqol_model"]]
        run(cmd)
        with open(res) as f:
            scores = {rec["degraded"]: float(rec["moslqo"]) for rec in csv.DictReader(f)}
        for row_path, _, d in part:
            row = json.loads(row_path.read_text())
            row["visqol"] = scores.get(d)
            row_path.write_text(json.dumps(row))

    # Chunks of 20 pairs, so that an interrupted run keeps most of its work.
    parts = [todo[i:i + 20] for i in range(0, len(todo), 20)]
    with tempfile.TemporaryDirectory() as tmp, ThreadPoolExecutor(max_workers=jobs) as pool:
        dirs = [Path(tmp) / str(i) for i in range(len(parts))]
        for d in dirs:
            d.mkdir()
        for n, fut in enumerate([pool.submit(batch, p, d) for p, d in zip(parts, dirs)], 1):
            fut.result()
            print(f"visqol {n}/{len(parts)}", file=sys.stderr)

# ---------------------------------------------------------------- speed

SPEED_CONFIGS = r"^(lc-.*-cbr128|he-.*-cbr48|hev2-.*-cbr32|xhe-.*-cbr64)$"


def concat_corpus(pcm):
    """The ten music items back to back: about 130 s of stereo 48 kHz."""
    wav = pcm / "concat.wav"
    if not wav.exists():
        parts = [sf.read(pcm / f"{n}.wav", dtype="int16", always_2d=True)[0] for n in MUSIC]
        x = np.vstack(parts)
        sf.write(wav, x, 48000, subtype="PCM_16", format="WAV")
        (x.astype("<f4") / 32768.0).tofile(pcm / "concat.f32")
    return wav, sf.info(str(wav)).duration


def best_of(cmd, n, tdir, tag):
    runs = []
    for i in range(n):
        t = str(tdir / f"{tag}.{i}.time")
        run(cmd, timed=t)
        runs.append(read_time(t))
    best = min(runs, key=lambda r: r["cpu"])
    return dict(cpu=best["cpu"], wall=min(r["wall"] for r in runs),
                rss_kb=max(r["rss_kb"] for r in runs))


def decoders_for(family, stream, ch=2):
    """Every decoder that takes this family, as (name, command writing to out)."""
    out = []
    if have("baac"):
        out.append(("ours", lambda o: [TOOLS["baac"], "dec", stream, o, "48000", "0",
                                        "--no-loudness"], "f32"))
    if family != "xhe" and have("ffmpeg"):
        out.append(("ffmpeg", lambda o: [TOOLS["ffmpeg"], "-v", "error", "-y", "-threads", "1",
                                          "-i", stream, "-c:a", "pcm_f32le", "-f", "wav", o], "wav"))
    if have("fdk_dec") and not stream.endswith(".es"):
        out.append(("fdk", lambda o: [TOOLS["fdk_dec"], stream, o], "wav"))
    if have("xaac_dec"):
        def xa(o):
            cmd = [TOOLS["xaac_dec"], f"-ifile:{stream}", f"-ofile:{o}", "-pcmsz:16",
                   "-peak_limiter_off:1", "-loudness_leveling:0"]
            if stream.endswith(".es"):
                cmd += [f"-imeta:{stream[:-3]}.txt", "-mp4:1"]
            return cmd
        out.append(("libxaac", xa, "wav"))
    if have("qaac") and family != "xhe":
        out.append(("apple", lambda o: wine("qaac") + ["--silent", "-D", "-b", "32", "-o", o, stream], "wav"))
    return out


def stage_speed(args):
    pcm = prepare_corpus()
    wav, secs = concat_corpus(pcm)
    d = WORK / "speed"
    d.mkdir(parents=True, exist_ok=True)
    rows = []
    if have("qaac"):
        base = best_of(wine("qaac") + ["--check"], args.repeat, d, "wine-baseline")
        rows.append(dict(kind="baseline", family="", codec="apple", config="wine qaac --check",
                         seconds=0, **base))
    streams = {}
    for c in cfg_list():
        if not re.search(SPEED_CONFIGS, c["id"]) or not all(map(have, c["needs"])):
            continue
        o = str(d / f"{c['id']}.{c['ext']}")
        r = best_of(c["cmd"](str(wav), str(pcm / "concat.f32"), o), args.repeat, d, c["id"])
        rows.append(dict(kind="encode", family=c["family"], codec=c["encoder"], config=c["id"],
                         seconds=secs, kbps=os.path.getsize(o) * 8 / secs / 1000, **r))
        streams[(c["family"], c["encoder"])] = o
        print("encode", c["id"], r, file=sys.stderr)
    # Decode one neutral stream per family (FDK's, libxaac's when FDK has
    # none) with every decoder, so that only the decoder differs.
    for fam in ("lc", "he", "hev2", "xhe"):
        src = streams.get((fam, "fdk")) or streams.get((fam, "libxaac"))
        if not src:
            continue
        for name, mk, ext in decoders_for(fam, src):
            o = str(d / f"dec-{fam}-{name}.{ext}")
            try:
                r = best_of(mk(o), args.repeat, d, f"dec-{fam}-{name}")
            except RuntimeError as e:
                print("decode failed", fam, name, e, file=sys.stderr)
                rows.append(dict(kind="decode", family=fam, codec=name, config=Path(src).name,
                                 seconds=secs, error=str(e)[:200]))
                continue
            rows.append(dict(kind="decode", family=fam, codec=name, config=Path(src).name,
                             seconds=secs, **r))
            os.remove(o)
            print("decode", fam, name, r, file=sys.stderr)
    (WORK / "speed.json").write_text(json.dumps(rows, indent=1))


# ---------------------------------------------------------------- compat

COMPAT_OURS = r"^(lc-ours-cbr(64|128|256)|he-ours-cbr(32|64)|hev2-ours-cbr(24|32)|xhe-ours-cbr(24|64|96))$"
COMPAT_THEIRS = r"^(lc-(ffmpeg|fdk|libxaac|apple)-cbr128|lcvbr-apple-tvbr82|he-(fdk|libxaac|apple)-cbr48|hev2-(fdk|libxaac)-cbr32|xhe-(fdk|libxaac)-cbr(24|64))$"


def load_decode(path, ext):
    if ext == "f32":
        x = np.fromfile(path, dtype="<f4").astype("float64")
        return x.reshape(-1, 2)
    return load(path)[0]


def snr_db(ref, x):
    n = ref - x
    return float(10 * np.log10(max(np.sum(ref ** 2), 1e-20) / max(np.sum(n ** 2), 1e-20)))


def stage_compat(args):
    pcm = prepare_corpus()
    rows = []
    tmp = WORK / "compat"
    tmp.mkdir(parents=True, exist_ok=True)
    for c in cfg_list():
        ours = re.search(COMPAT_OURS, c["id"])
        theirs = re.search(COMPAT_THEIRS, c["id"])
        if not (ours or theirs):
            continue
        cache = tmp / f"{c['id']}.json"
        if cache.exists():
            rows += json.loads(cache.read_text())
            continue
        first = len(rows)
        for item in MUSIC:
            stream = str(WORK / "quality" / c["id"] / f"{item}.{c['ext']}")
            if not os.path.exists(stream):
                continue
            ref, _ = load(str(pcm / f"{item}.wav"))
            decs = {}
            for name, mk, ext in decoders_for(c["family"], stream):
                if theirs and name not in ("ours", "ffmpeg", "libxaac"):
                    continue
                o = str(tmp / f"{c['id']}.{item}.{name}.{ext}")
                try:
                    run(mk(o))
                    x = load_decode(o, ext)
                    if len(x) < 48000:
                        raise RuntimeError(f"{len(x)} samples decoded")
                    decs[name] = x
                except Exception as e:
                    rows.append(dict(config=c["id"], item=item, decoder=name, ok=False,
                                     error=str(e)[-200:]))
                finally:
                    if os.path.exists(o):
                        os.remove(o)
            if "ours" not in decs:
                continue
            base, _, _, _ = align(ref, decs["ours"])
            for name, x in decs.items():
                a, lag, gain, _ = align(ref, x)
                rows.append(dict(config=c["id"], item=item, decoder=name, ok=True,
                                 snr_vs_ours=snr_db(base, a) if name != "ours" else None,
                                 snr_vs_source=snr_db(ref, a), delay=lag,
                                 gain_db=20 * np.log10(max(gain, 1e-9))))
        cache.write_text(json.dumps(rows[first:]))
        print("compat", c["id"], file=sys.stderr)
    (WORK / "compat.json").write_text(json.dumps(rows, indent=1))


# ---------------------------------------------------------------- lossless
#
# LOSSLESS.  The stage measures bAAC's lossless mode (the `baac flac`
# commands) against the pinned flac 1.4.3, FFmpeg's FLAC and ALAC
# encoders and, when it can run, Apple's refalac, and judges the gates
# of the lossless mode.
#
# Size.  One pass over the corpus concatenation (about 130 s).  FLAC
# rows also record frame bytes, the file without its metadata blocks,
# because flac writes padding and a seek table by default; every size
# gate between FLAC encoders compares frame bytes.
#
# Speed.  Every timed process is pinned to one core with taskset and
# measured as user plus system CPU time (wait4).  A gate takes at least
# five repeats; inside a repeat the reference and bAAC run back to back,
# in alternating order, and yield one paired ratio, bAAC's time over the
# reference's.  The threshold is 1 plus the reference's spread, (max -
# min) / median of its times over the repeats.  A gate has passed when
# every paired ratio is at or below the threshold, failed when every one
# is above it, and is undecided when they straddle it.
#
# What is timed.  The gates are judged on the long run: one process over
# the input repeated until the fastest command of the gate set takes at
# least 2 CPU seconds, which keeps the start of a process (about 1 ms)
# out of the figure.  The encode gates are judged on raw input: flac
# reads raw PCM about 3 % faster than WAV and bAAC reads both alike, so
# raw input is the case that favours bAAC least.  The other three
# figures of each encode gate are measured and recorded beside it, each
# labelled: long run from WAV, and one process per corpus item (which
# adds each tool's start cost) from raw and from WAV.
#
# Decode.  The neutral streams are flac's own (-5 and -8).  Against
# `flac -d` both sides verify the MD5; against FFmpeg neither does and
# both check the frame CRCs (FFmpeg is asked to with -err_detect
# crccheck).  Every decoder writes a WAV file to /dev/null.
#
# Exactness.  Every stream is decoded by its own decoder and by the
# others that take it (bAAC's by flac and FFmpeg, theirs by bAAC) and
# each decode is compared with the source bytes.
#
# Corpus additions.  Seven recordings from the FLAC decoder testbench,
# decoded with `flac -d`: five 96 kHz 24-bit files, one 5.1 file and one
# 16-bit stereo file.  They were the first held-out set; they have been
# run and have informed work on the encoder, so they now count as corpus
# and may be tuned on.  Each set keeps its gates at level 5, and the
# enlarged corpus (the ten items and these) has size totals and size
# gates of its own at levels 5 and 8.
#
# Held-out recordings.  Three recordings that no encoder or decoder has
# been run on before the recorded run: a 96 kHz 24-bit file, twenty
# seconds of a 5.1 film master and a 16-bit stereo file
# (fetch-heldout.sh).  The stage touches them only in a complete run
# with --check that starts on a quiet host, writes a ledger entry
# before it reads the first of them, and records in its result whether
# that run was the first.  A later run with another build of baac is
# not a gate result for these recordings.  Each set's total frame bytes
# gate at level 5, with an encode gate and two decode gates.
#
# Host.  The run records the processor, the compiler and the versions of
# the references, and measures the CPU the rest of the host uses: over
# the minute before the run and over the run, the host's busy time from
# /proc/stat less the CPU time of the harness and of every process it
# ran, divided by the elapsed time.  The host is quiet when both are at
# most QUIET_CPU.  The load average, recorded for information, is not
# the test: it counts the tasks that happen to be runnable at its
# sampling ticks, so on a host running containers that wake briefly and
# often it reads far above the CPU they use.  A run on a host that is not
# quiet is recorded as such and is not a gate result.

LOSSLESS_LEVELS = list(range(9))
MIN_CPU_SECONDS = 2.0
# The repeated input is sized for this much over the minimum, so that a
# run a little faster than the one that sized it still counts.
LONG_MARGIN = 1.4
QUIET_CPU = 2.0     # CPUs' worth the rest of the host may use
QUIET_WINDOW = 60   # seconds the host is measured before a run starts
CARRIED_REFALAC = dict(codec="alac-apple-ref", seconds=130.3173125, kbps=690.2499619918112,
                       bit_exact=True, carried="2026-10-30")
TESTBENCH = Path(os.environ.get("BAAC_FLAC_TESTBENCH", HERE / "work" / "flac-test-files"))
HELDOUT_DIR = Path(os.environ.get("BAAC_HELDOUT", HERE / "work" / "heldout"))
# A set is (id, label, format, sources); a source is (file, skip, until),
# the last two as `flac -d --skip` and `--until` take them, or None for
# the whole file.
#
# The first held-out set, now corpus: files of the testbench's subset.
CORPUS_ADDITIONS = [
    ("hires", "96 kHz, 24-bit stereo", dict(sample_rate=96000, channels=2, bits=24),
     [("28 - high resolution audio, default settings.flac", None, None),
      ("29 - high resolution audio, blocksize 16384.flac", None, None),
      ("30 - high resolution audio, blocksize 13456.flac", None, None),
      ("31 - high resolution audio, using only 32nd order predictors.flac", None, None),
      ("32 - high resolution audio, partition order 8 containing escaped partitions.flac",
       None, None)]),
    ("surround", "5.1, 44.1 kHz, 16-bit", dict(sample_rate=44100, channels=6, bits=16),
     [("41 - 6 channels (5.1).flac", None, None)]),
    ("stereo16", "44.1 kHz, 16-bit stereo", dict(sample_rate=44100, channels=2, bits=16),
     [("01 - blocksize 4096.flac", None, None)]),
]
# The held-out set.  The span of the film master was fixed before the
# file was ever decoded: twenty seconds from the fifth minute.
HELD_OUT = [
    ("hires", "96 kHz, 24-bit stereo", dict(sample_rate=96000, channels=2, bits=24),
     [("open-goldberg-variatio-4.flac", None, None)]),
    ("surround", "5.1, 48 kHz, 16-bit", dict(sample_rate=48000, channels=6, bits=16),
     [("sintel-master-51.flac", "5:00.00", "5:20.00")]),
    ("stereo16", "48 kHz, 16-bit stereo", dict(sample_rate=48000, channels=2, bits=16),
     [("sintel_trailer-audio.flac", None, None)]),
]


def expected_gates():
    """The ids of every gate a complete run judges, in the order of the
    report.  The check holds a complete result against this list, so a
    run that lost a gate cannot pass for lack of it."""
    ids = ["size-5", "size-8", "enc-5", "enc-8"]
    ids += [f"dec-{level}-{ref}" for level in (5, 8) for ref in ("flac", "ffmpeg")]
    ids += ["alac-size-ffmpeg", "alac-size-refalac", "alac-enc", "alac-dec"]
    set_gates = ("size", "enc", "dec-flac", "dec-ffmpeg")
    ids += [f"added-{s[0]}-{g}" for s in CORPUS_ADDITIONS for g in set_gates]
    ids += ["size-5-enlarged", "size-8-enlarged"]
    ids += [f"held-{s[0]}-{g}" for s in HELD_OUT for g in set_gates]
    return ids


def cpu_run(cmd, core, stdout=subprocess.DEVNULL):
    """Run cmd pinned to `core`; return (CPU seconds, peak RSS in KiB)."""
    p = subprocess.Popen(["taskset", "-c", str(core)] + cmd, stdout=stdout,
                         stderr=subprocess.PIPE)
    err = p.stderr.read()
    _, status, usage = os.wait4(p.pid, 0)
    p.returncode = os.waitstatus_to_exitcode(status)
    if p.returncode != 0:
        raise RuntimeError(f"{shlex.join(cmd)} failed ({p.returncode}): "
                           f"{err.decode(errors='replace')[-600:]}")
    return usage.ru_utime + usage.ru_stime, usage.ru_maxrss


def cpu_of(cmds, core):
    """CPU seconds and peak RSS of a sequence of commands, each its own process."""
    total, rss = 0.0, 0
    for cmd in cmds:
        t, r = cpu_run(cmd, core)
        total += t
        rss = max(rss, r)
    return total, rss


def paired_times(ours, theirs, repeats, core):
    """Each repeat runs both command sequences back to back, in alternating
    order; returns [(our CPU seconds, their CPU seconds), ...]."""
    pairs = []
    for i in range(repeats):
        if i % 2 == 0:
            a = cpu_of(ours, core)[0]
            b = cpu_of(theirs, core)[0]
        else:
            b = cpu_of(theirs, core)[0]
            a = cpu_of(ours, core)[0]
        pairs.append((a, b))
    return pairs


def speed_verdict(pairs):
    """The statistic of a speed gate from its paired times.  A gate with
    a run shorter than MIN_CPU_SECONDS is undecided whatever its ratios."""
    ratios = [a / b for a, b in pairs]
    refs = [b for _, b in pairs]
    spread = (max(refs) - min(refs)) / statistics.median(refs)
    threshold = 1.0 + spread
    shortest = min(min(a, b) for a, b in pairs)
    if shortest < MIN_CPU_SECONDS:
        verdict = "undecided"
    elif max(ratios) <= threshold:
        verdict = "passed"
    elif min(ratios) > threshold:
        verdict = "failed"
    else:
        verdict = "undecided"
    return dict(ratio_median=statistics.median(ratios), ratio_min=min(ratios),
                ratio_max=max(ratios), reference_spread=spread, threshold=threshold,
                ours_median=statistics.median(a for a, _ in pairs),
                reference_median=statistics.median(refs),
                min_cpu=shortest, verdict=verdict,
                pairs=[[round(a, 4), round(b, 4)] for a, b in pairs])


def size_verdict(ours, reference):
    """A size gate takes no tolerance: one byte more has failed."""
    return dict(ours=ours, reference=reference,
                verdict="passed" if ours <= reference else "failed")


def flac_frame_bytes(path):
    """A FLAC file without its marker and metadata blocks."""
    with open(path, "rb") as f:
        if f.read(4) != b"fLaC":
            raise RuntimeError(f"{path} is not a FLAC file")
        while True:
            head = f.read(4)
            f.seek(int.from_bytes(head[1:], "big"), 1)
            if head[0] & 0x80:
                return os.path.getsize(path) - f.tell()


def same_bytes(a, b):
    """Whether two decodes are the same samples: byte for byte."""
    return a == b


def tool_version(cmd):
    try:
        out = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        return out.stdout.decode(errors="replace").splitlines()[0].strip()
    except (OSError, IndexError):
        return ""


def pick_core():
    """The core that was idlest over half a second, by /proc/stat."""
    def sample():
        idle = {}
        for line in Path("/proc/stat").read_text().splitlines():
            m = re.match(r"cpu(\d+) (.*)", line)
            if m:
                v = [int(x) for x in m.group(2).split()]
                idle[int(m.group(1))] = (v[3] + v[4], sum(v))
        return idle
    try:
        a = sample()
        time.sleep(0.5)
        b = sample()
    except OSError:
        return 0
    allowed = os.sched_getaffinity(0)
    busy = {c: 1.0 - (b[c][0] - a[c][0]) / max(1, b[c][1] - a[c][1]) for c in b if c in allowed}
    return min(busy, key=lambda c: (round(busy[c], 2), -c)) if busy else 0


def busy_seconds(stat_text, ticks):
    """The CPU seconds the whole host has been busy, from the first line
    of /proc/stat: user, nice, system, irq, softirq and steal.  Idle and
    iowait are not busy, and guest time is inside user and nice already."""
    v = [int(x) for x in stat_text.split("\n", 1)[0].split()[1:]]
    return (v[0] + v[1] + v[2] + sum(v[5:8])) / ticks


def cpu_sample():
    """The host's busy CPU seconds, the CPU seconds of this process and
    of every child it has waited for, and a monotonic clock."""
    t = os.times()
    return dict(busy=busy_seconds(Path("/proc/stat").read_text(), os.sysconf("SC_CLK_TCK")),
                own=t.user + t.system + t.children_user + t.children_system,
                clock=time.monotonic())


def other_cpu(a, b):
    """The CPUs' worth that everything except this process and its
    children used between two samples, on average."""
    return max(0.0, (b["busy"] - a["busy"] - (b["own"] - a["own"])) / (b["clock"] - a["clock"]))


def quiet_verdict(host):
    """Whether the rest of the host was quiet before and during a run, and
    the reason when it was not."""
    start, during = host.get("other_cpu_start"), host.get("other_cpu_run")
    limit = host.get("quiet_cpu", QUIET_CPU)
    if start is None or during is None:
        return False, "the result does not measure the rest of the host"
    if max(start, during) > limit:
        return False, (f"the rest of the host used {during:.2f} CPUs on average during the run "
                       f"and {start:.2f} in the minute before it, above {limit}")
    return True, ""


def host_info(core):
    cpu = ""
    try:
        for line in Path("/proc/cpuinfo").read_text().splitlines():
            if line.startswith("model name"):
                cpu = line.split(":", 1)[1].strip()
                break
    except OSError:
        pass
    compiler = build_type = ""
    cache = Path(TOOLS["baac"]).parent / "CMakeCache.txt"
    if cache.exists():
        text = cache.read_text()
        m = re.search(r"^CMAKE_CXX_COMPILER:\w+=(.*)$", text, re.M)
        if m:
            compiler = tool_version([m.group(1), "--version"])
        m = re.search(r"^CMAKE_BUILD_TYPE:\w+=(.*)$", text, re.M)
        build_type = m.group(1) if m else ""
    governor = Path(f"/sys/devices/system/cpu/cpu{core}/cpufreq/scaling_governor")
    return dict(cpu=cpu, cores=os.cpu_count(), kernel=platform.release(), core=core,
                governor=governor.read_text().strip() if governor.exists() else "",
                compiler=compiler, build_type=build_type)


def git_state():
    try:
        rev = subprocess.run(["git", "-C", str(HERE), "rev-parse", "--short", "HEAD"],
                             stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout.decode().strip()
        dirty = subprocess.run(["git", "-C", str(HERE), "status", "--porcelain", "--", ".."],
                               stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout.strip()
        return rev + ("+changes" if dirty else "")
    except OSError:
        return ""


def raw_args(fmt):
    """flac's options for headerless input of a format."""
    return ["--force-raw-format", "--endian=little", "--sign=signed",
            f"--channels={fmt['channels']}", f"--bps={fmt['bits']}",
            f"--sample-rate={fmt['sample_rate']}"]


def enc_baac(level, src, out, fmt=None):
    raw = ["--raw", str(fmt["sample_rate"]), str(fmt["channels"]), str(fmt["bits"])] if fmt else []
    return [TOOLS["baac"], "flac", "enc", "--level", str(level)] + raw + [str(src), str(out)]


def enc_flac(level, src, out, fmt=None):
    return [TOOLS["flac"], "-s", "-f", f"-{level}", "-o", str(out)] + \
        (raw_args(fmt) if fmt else []) + [str(src)]


def dec_baac(stream, out, md5=True, raw=False):
    return [TOOLS["baac"], "flac", "dec"] + ([] if md5 else ["--no-md5"]) + \
        (["--raw"] if raw else []) + [str(stream), str(out)]


def dec_flac(stream, out, raw=False, skip=None, until=None):
    extra = ["--force-raw-format", "--endian=little", "--sign=signed"] if raw else []
    extra += [f"--skip={skip}"] if skip else []
    extra += [f"--until={until}"] if until else []
    return [TOOLS["flac"], "-d", "-s", "-f"] + extra + ["-o", str(out), str(stream)]


def ffmpeg_cmd(*args):
    """An FFmpeg command of the lossless stage: quiet, no terminal, one
    thread, and the arguments given."""
    return [TOOLS["ffmpeg"], "-v", "error", "-nostdin", "-y", "-threads", "1", *args]


def dec_ffmpeg(stream, out, bits=16, raw=False):
    """FFmpeg's decode with the frame CRC check on; it verifies no MD5."""
    width = 16 if bits <= 16 else 24 if bits <= 24 else 32
    return ffmpeg_cmd("-err_detect", "crccheck", "-i", str(stream), "-c:a", f"pcm_s{width}le",
                      "-f", f"s{width}le" if raw else "wav", str(out))


def decoded(cmd_for, core):
    """The bytes a decode command writes to standard output."""
    p = subprocess.run(["taskset", "-c", str(core)] + cmd_for("-"), stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE)
    if p.returncode != 0:
        return None
    return p.stdout


def write_wav(path, raw, fmt):
    """A WAV file around raw PCM: the plain header for 16-bit stereo, the
    extensible one for everything else, as bAAC and flac write them."""
    width = (fmt["bits"] + 7) // 8
    ch, rate = fmt["channels"], fmt["sample_rate"]
    extensible = ch > 2 or fmt["bits"] > 16
    masks = {1: 0x4, 2: 0x3, 3: 0x7, 4: 0x33, 5: 0x37, 6: 0x3F, 7: 0x70F, 8: 0x63F}
    body = b"WAVEfmt "
    if extensible:
        body += (40).to_bytes(4, "little") + (0xFFFE).to_bytes(2, "little")
    else:
        body += (16).to_bytes(4, "little") + (1).to_bytes(2, "little")
    body += ch.to_bytes(2, "little") + rate.to_bytes(4, "little")
    body += (rate * ch * width).to_bytes(4, "little") + (ch * width).to_bytes(2, "little")
    body += (width * 8).to_bytes(2, "little")
    if extensible:
        body += (22).to_bytes(2, "little") + fmt["bits"].to_bytes(2, "little")
        body += masks[ch].to_bytes(4, "little")
        body += bytes.fromhex("0100000000001000800000aa00389b71")
    size = os.path.getsize(raw)
    with open(path, "wb") as out, open(raw, "rb") as src:
        out.write(b"RIFF" + (4 + len(body) - 4 + 8 + size + 4).to_bytes(4, "little") + body)
        out.write(b"data" + size.to_bytes(4, "little"))
        shutil.copyfileobj(src, out, 1 << 20)


def repeat_file(src, dst, reps):
    with open(dst, "wb") as out:
        for _ in range(reps):
            with open(src, "rb") as f:
                shutil.copyfileobj(f, out, 1 << 20)


def long_input(d, tag, once_raw, fmt, core, reps=None):
    """The input repeated until bAAC's decode without MD5, the fastest
    command of a gate set, takes MIN_CPU_SECONDS, or `reps` times when the
    caller fixes the count: returns the repetition count and writes
    <tag>.long.raw and <tag>.long.wav."""
    probe = d / f"{tag}.probe.flac"
    raw = d / f"{tag}.long.raw"
    fixed = reps is not None
    if not fixed:
        cpu_run(enc_flac(5, once_raw, probe, fmt), core)
        t = min(cpu_run(dec_baac(probe, "/dev/null", md5=False), core)[0] for _ in range(3))
        reps = max(1, math.ceil(LONG_MARGIN * MIN_CPU_SECONDS / max(t, 1e-4)))
    for _ in range(4):
        repeat_file(once_raw, raw, reps)
        if fixed:
            break
        cpu_run(enc_flac(5, raw, probe, fmt), core)
        t = min(cpu_run(dec_baac(probe, "/dev/null", md5=False), core)[0] for _ in range(2))
        if t >= 0.9 * LONG_MARGIN * MIN_CPU_SECONDS:
            break
        reps = math.ceil(reps * LONG_MARGIN * MIN_CPU_SECONDS / max(t, 1e-4))
    write_wav(d / f"{tag}.long.wav", raw, fmt)
    probe.unlink(missing_ok=True)
    return reps


def check_held_out_format(name, found, expected):
    """A held-out file of another format than the set's stops the stage."""
    if found != expected:
        raise SystemExit(f"held-out file \"{name}\" is {found['sample_rate']} Hz, "
                         f"{found['channels']} channels, {found['bits']} bits; the set expects "
                         f"{expected['sample_rate']} Hz, {expected['channels']} channels, "
                         f"{expected['bits']} bits")


def flac_properties(path):
    """Sample rate, channels and bit depth from a FLAC file's STREAMINFO."""
    with open(path, "rb") as f:
        head = f.read(4 + 4 + 34)
    if head[:4] != b"fLaC" or (head[4] & 0x7F) != 0:
        raise SystemExit(f"held-out file \"{path}\" has no STREAMINFO")
    v = int.from_bytes(head[18:26], "big")
    return dict(sample_rate=v >> 44, channels=((v >> 41) & 7) + 1, bits=((v >> 36) & 31) + 1), \
        v & ((1 << 36) - 1)


def file_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def held_out_entry(ledger_path, date, baac_sha256, git):
    """Books a run on the held-out recordings in the ledger, before the
    run reads the first of them, and returns what the result records:
    whether it is the first run, and whether it measures the build of
    baac the first run measured.  An interrupted run stays booked: the
    recordings were read."""
    runs = json.loads(ledger_path.read_text())["runs"] if ledger_path.exists() else []
    record = dict(touched=True, run=len(runs) + 1, first_run=not runs,
                  same_build_as_first=not runs or runs[0]["baac_sha256"] == baac_sha256,
                  first_date=runs[0]["date"] if runs else date, baac_sha256=baac_sha256)
    runs.append(dict(date=date, baac_sha256=baac_sha256, git=git))
    ledger_path.parent.mkdir(parents=True, exist_ok=True)
    ledger_path.write_text(json.dumps(dict(
        note="Runs of the lossless stage on the held-out recordings. Deleting this file does "
             "not make the recordings unseen.", runs=runs), indent=1) + "\n")
    return record


def alac_rows(have_refalac, measured):
    """The ALAC reference rows: refalac measured when it runs here, else
    the row carried from the run of 2026-10-30, which is compared on size
    only and marked."""
    rows = [r for r in measured if r["codec"] == "alac-ffmpeg"]
    ref = [r for r in measured if r["codec"] == "alac-apple-ref"]
    rows += ref if have_refalac and ref else [dict(CARRIED_REFALAC)]
    return rows


def measure_gate(name, ours, theirs, repeats, core, log=True):
    r = speed_verdict(paired_times(ours, theirs, repeats, core))
    if log:
        print(f"  {name}: {r['ratio_median']:.4f} ({r['ratio_min']:.4f} to {r['ratio_max']:.4f}), "
              f"threshold {r['threshold']:.4f}: {r['verdict']}", file=sys.stderr)
    return r


def stage_lossless(args):
    for tool in ("baac", "flac", "ffmpeg"):
        if not have(tool):
            raise SystemExit(f"the lossless stage needs {tool} ({TOOLS[tool]})")
    flac_version = tool_version([TOOLS["flac"], "--version"])
    if flac_version != "flac 1.4.3" and not args.any_flac:
        raise SystemExit(f"FLAC is \"{flac_version}\", not the pinned flac 1.4.3: run "
                         "./setup-refs.sh and source work/ext/env.sh (or pass --any-flac for a "
                         "run that is not a gate result)")
    d = WORK / "lossless"
    d.mkdir(parents=True, exist_ok=True)
    # The rest of the host over the minute before the run, then from here
    # to the end of the run.
    print(f"lossless: measuring the host for {QUIET_WINDOW} s", file=sys.stderr)
    before = cpu_sample()
    time.sleep(QUIET_WINDOW)
    start = cpu_sample()
    other_start = other_cpu(before, start)
    core = args.core if args.core is not None else pick_core()
    repeats = max(args.repeat, 5)
    only = re.compile(args.only or ".")
    ff = TOOLS["ffmpeg"]
    load_start = os.getloadavg()
    started = datetime.datetime.now().astimezone().isoformat(timespec="seconds")
    host = host_info(core)
    print(f"lossless: core {core}, {repeats} repeats, the rest of the host using "
          f"{other_start:.2f} CPUs, load {load_start[0]:.2f}", file=sys.stderr)

    # The corpus: the ten items back to back, once and repeated.
    corpus = WORK / "corpus"
    if not (corpus / f"{MUSIC[0]}.wav").exists():
        subprocess.run([str(HERE / "corpus.sh"), str(corpus)], check=True)
    fmt = dict(sample_rate=48000, channels=2, bits=16)
    items = []
    with open(d / "corpus.raw", "wb") as out:
        for name in MUSIC:
            with wave.open(str(corpus / f"{name}.wav"), "rb") as w:
                if (w.getframerate(), w.getnchannels(), w.getsampwidth()) != (48000, 2, 2):
                    raise SystemExit(f"corpus item {name} is not 48 kHz 16-bit stereo")
                pcm = w.readframes(w.getnframes())
            (d / f"item.{name}.raw").write_bytes(pcm)
            write_wav(d / f"item.{name}.wav", d / f"item.{name}.raw", fmt)
            items.append(name)
            out.write(pcm)
    write_wav(d / "corpus.wav", d / "corpus.raw", fmt)
    source = (d / "corpus.raw").read_bytes()
    seconds = len(source) / (48000 * 4)
    reps = long_input(d, "corpus", d / "corpus.raw", fmt, core, args.reps)
    print(f"corpus: {seconds:.1f} s, repeated {reps} times for timing", file=sys.stderr)

    # ---- sizes and exactness: one pass over the corpus -----------------
    def flac_row(codec, encoder, level, enc_cmd):
        o = d / f"{codec}.flac"
        cpu_run(enc_cmd(d / "corpus.wav", o), core)
        exact = True
        for dec in (lambda out: dec_baac(o, out, raw=True), lambda out: dec_flac(o, out, raw=True),
                    lambda out: dec_ffmpeg(o, out, raw=True)):
            exact = exact and same_bytes(decoded(dec, core), source)
        size = os.path.getsize(o)
        return dict(codec=codec, family="flac", encoder=encoder, level=level, seconds=seconds,
                    bytes=size, kbps=size * 8 / seconds / 1000, frame_bytes=flac_frame_bytes(o),
                    bit_exact=bool(exact))

    rows = []
    for level in LOSSLESS_LEVELS:
        rows.append(flac_row(f"baac-{level}", "baac", level,
                             lambda src, o, level=level: enc_baac(level, src, o)))
    for level in LOSSLESS_LEVELS:
        rows.append(flac_row(f"flac-{level}", "flac", level,
                             lambda src, o, level=level: enc_flac(level, src, o)))
    for level in (5, 8):
        rows.append(flac_row(f"flac-ffmpeg-{level}", "ffmpeg", level,
                             lambda src, o, level=level: ffmpeg_cmd(
                                 "-i", str(src), "-c:a", "flac", "-compression_level", str(level),
                                 str(o))))
    alac_enc = lambda src, o: ffmpeg_cmd("-i", str(src), "-c:a", "alac", str(o))
    alac_dec = lambda stream, out, raw=False: ffmpeg_cmd(
        "-i", str(stream), "-c:a", "pcm_s16le", "-f", "s16le" if raw else "wav", str(out))
    cpu_run(alac_enc(d / "corpus.wav", d / "alac-ffmpeg.m4a"), core)
    size = os.path.getsize(d / "alac-ffmpeg.m4a")
    measured_alac = [dict(codec="alac-ffmpeg", family="alac", encoder="ffmpeg", seconds=seconds,
                          bytes=size, kbps=size * 8 / seconds / 1000,
                          bit_exact=bool(same_bytes(decoded(
                              lambda out: alac_dec(d / "alac-ffmpeg.m4a", out, raw=True), core),
                              source)))]
    if have("refalac"):
        o = d / "alac-apple-ref.m4a"
        run(wine("refalac") + ["--silent", "-o", str(o), str(d / "corpus.wav")])
        size = os.path.getsize(o)
        measured_alac.append(dict(codec="alac-apple-ref", family="alac", encoder="refalac",
                                  seconds=seconds, bytes=size, kbps=size * 8 / seconds / 1000,
                                  bit_exact=bool(same_bytes(decoded(
                                      lambda out: alac_dec(o, out, raw=True), core), source))))
    alac = alac_rows(have("refalac"), measured_alac)
    by = {r["codec"]: r for r in rows + alac}
    for r in rows:
        print(f"  {r['codec']}: {r['kbps']:.2f} kbit/s, {r['frame_bytes']} frame bytes, "
              f"bit-exact {r['bit_exact']}", file=sys.stderr)

    # ---- speed of every row, on the long input from WAV ----------------
    long_wav, long_raw = d / "corpus.long.wav", d / "corpus.long.raw"
    row_repeats = 3
    if not args.gates_only:
        for r in rows:
            level, enc = r["level"], r["encoder"]
            stream = d / f"{r['codec']}.long.flac"
            if enc == "baac":
                e, dcmd = enc_baac(level, long_wav, "/dev/null"), dec_baac(stream, "/dev/null")
                make = enc_baac(level, long_wav, stream)
            elif enc == "flac":
                e, dcmd = enc_flac(level, long_wav, "/dev/null"), dec_flac(stream, "/dev/null")
                make = enc_flac(level, long_wav, stream)
            else:
                e = ffmpeg_cmd("-i", str(long_wav), "-c:a", "flac", "-compression_level",
                               str(level), "-f", "flac", "/dev/null")
                make = e[:-3] + [str(stream)]
                dcmd = dec_ffmpeg(stream, "/dev/null")
            cpu_run(make, core)
            et = [cpu_run(e, core) for _ in range(row_repeats)]
            dt = [cpu_run(dcmd, core) for _ in range(row_repeats)]
            r.update(enc_cpu=statistics.median(t for t, _ in et) / reps,
                     enc_rss_kb=max(m for _, m in et),
                     dec_cpu=statistics.median(t for t, _ in dt) / reps,
                     dec_rss_kb=max(m for _, m in dt))
            if not (enc == "flac" and level in (5, 8)) and not (enc == "baac" and level == 5):
                stream.unlink()
            print(f"  {r['codec']}: enc {seconds / r['enc_cpu']:.0f}x, dec "
                  f"{seconds / r['dec_cpu']:.0f}x", file=sys.stderr)
    for level in (5, 8):  # the neutral streams of the decode gates
        if not (d / f"flac-{level}.long.flac").exists():
            cpu_run(enc_flac(level, long_wav, d / f"flac-{level}.long.flac"), core)
    if not (d / "baac-5.long.flac").exists():
        cpu_run(enc_baac(5, long_wav, d / "baac-5.long.flac"), core)

    # ---- gates ---------------------------------------------------------
    gates, figures = [], []

    def gate(gid, group, text, result, judged_on=None):
        g = dict(id=gid, group=group, description=text, **result)
        if judged_on:
            g["judged_on"] = judged_on
        gates.append(g)
        return g

    for level in (5, 8):
        gate(f"size-{level}", "flac", f"bAAC level {level} frame bytes against flac -{level}",
             dict(kind="size", **size_verdict(by[f"baac-{level}"]["frame_bytes"],
                                              by[f"flac-{level}"]["frame_bytes"])))
    variants = {
        "long-raw": lambda tool, level: [tool(level, long_raw, "/dev/null", fmt)],
        "long-wav": lambda tool, level: [tool(level, long_wav, "/dev/null")],
        "per-file-raw": lambda tool, level: [tool(level, d / f"item.{n}.raw", "/dev/null", fmt)
                                             for _ in range(reps) for n in items],
        "per-file-wav": lambda tool, level: [tool(level, d / f"item.{n}.wav", "/dev/null")
                                             for _ in range(reps) for n in items],
    }
    for level in (5, 8):
        gid = f"enc-{level}"
        if not only.search(gid):
            continue
        for variant, mk in variants.items():
            r = measure_gate(f"{gid} {variant}", mk(enc_baac, level), mk(enc_flac, level), repeats,
                             core)
            figures.append(dict(measure=gid, variant=variant, judged=variant == "long-raw", **r))
            if variant == "long-raw":
                gate(gid, "flac", f"encode at level {level} against flac -{level}",
                     dict(kind="speed", **r), "one process over the repeated corpus, raw input")
    # Decode of flac's own streams.
    for level in (5, 8):
        stream = d / f"flac-{level}.long.flac"
        for name, n in ((f"item.{n}", n) for n in items):
            if not (d / f"{name}.flac-{level}.flac").exists():
                cpu_run(enc_flac(level, d / f"{name}.wav", d / f"{name}.flac-{level}.flac"), core)
        per_file = [d / f"item.{n}.flac-{level}.flac" for _ in range(reps) for n in items]
        for ref, ours_md5, their in (("flac", True, dec_flac), ("ffmpeg", False, dec_ffmpeg)):
            gid = f"dec-{level}-{ref}"
            if not only.search(gid):
                continue
            checks = "MD5 verified by both" if ref == "flac" else \
                "frame CRCs checked by both, no MD5"
            r = measure_gate(f"{gid} long", [dec_baac(stream, "/dev/null", md5=ours_md5)],
                             [their(stream, "/dev/null")], repeats, core)
            figures.append(dict(measure=gid, variant="long", judged=True, **r))
            gate(gid, "decode", f"decode of flac -{level} streams against {ref} ({checks})",
                 dict(kind="speed", **r), "one process over the repeated corpus")
            r = measure_gate(f"{gid} per-file",
                             [dec_baac(s, "/dev/null", md5=ours_md5) for s in per_file],
                             [their(s, "/dev/null") for s in per_file], repeats, core)
            figures.append(dict(measure=gid, variant="per-file", judged=False, **r))
    # ALAC, at the default level.
    ours5 = by["baac-5"]
    for r in alac:
        ref_bytes = r.get("bytes") or round(r["kbps"] * r["seconds"] * 125)
        ours_bytes = ours5["bytes"] if r["seconds"] == seconds else \
            round(ours5["kbps"] * r["seconds"] * 125)
        note = f" (row carried from {r['carried']}, size only)" if r.get("carried") else ""
        gate(f"alac-size-{'refalac' if r['codec'] == 'alac-apple-ref' else 'ffmpeg'}", "alac",
             f"bAAC level 5 stream against {r['codec']}{note}",
             dict(kind="size", **size_verdict(ours_bytes, ref_bytes)))
    if only.search("alac-enc"):
        r = measure_gate("alac-enc", [enc_baac(5, long_wav, d / "timing.flac")],
                         [alac_enc(long_wav, d / "timing.m4a")], repeats, core)
        figures.append(dict(measure="alac-enc", variant="long-wav", judged=True, **r))
        gate("alac-enc", "alac", "encode at level 5 against FFmpeg ALAC", dict(kind="speed", **r),
             "one process over the repeated corpus, WAV input, both writing a file")
        by["alac-ffmpeg"].update(enc_cpu=r["reference_median"] / reps)
    if only.search("alac-dec"):
        if not (d / "timing.m4a").exists():
            cpu_run(alac_enc(long_wav, d / "timing.m4a"), core)
        r = measure_gate("alac-dec", [dec_baac(d / "baac-5.long.flac", "/dev/null")],
                         [alac_dec(d / "timing.m4a", "/dev/null")], repeats, core)
        figures.append(dict(measure="alac-dec", variant="long", judged=True, **r))
        gate("alac-dec", "alac", "decode of its own level 5 stream against FFmpeg decoding ALAC",
             dict(kind="speed", **r), "one process over the repeated corpus")
        by["alac-ffmpeg"].update(dec_cpu=r["reference_median"] / reps)
    for f in ("timing.flac", "timing.m4a"):
        (d / f).unlink(missing_ok=True)

    # ---- recordings beyond the ten items --------------------------------
    def measure_set(prefix, group, what, directory, set_id, label, expected, sources, levels):
        """One set of recordings: sizes file by file at `levels`, exactness
        and the level 5 gates on the set as one input.  Returns the per-file
        records and the set's frame bytes by level and encoder."""
        records = []
        totals = {level: dict(baac=0, flac=0) for level in levels}
        set_raw = d / f"{prefix}.{set_id}.raw"
        with open(set_raw, "wb") as out:
            for name, skip, until in sources:
                path = directory / name
                if not path.exists():
                    raise SystemExit(f"{what} file \"{path}\" is missing")
                found, _ = flac_properties(path)
                check_held_out_format(name, found, expected)
                pcm = decoded(lambda o: dec_flac(path, o, raw=True, skip=skip, until=until), core)
                out.write(pcm)
                raw = d / f"{prefix}.one.raw"
                raw.write_bytes(pcm)
                record = dict(set=set_id, file=Path(name).stem, **expected,
                              seconds=len(pcm) / (expected["sample_rate"] * expected["channels"]
                                                  * ((expected["bits"] + 7) // 8)))
                if skip or until:
                    record["span"] = [skip, until]
                exact = True
                for level in levels:
                    ours, theirs = d / f"{prefix}.baac.flac", d / f"{prefix}.flac.flac"
                    cpu_run(enc_baac(level, raw, ours, expected), core)
                    cpu_run(enc_flac(level, raw, theirs, expected), core)
                    exact = exact and all(same_bytes(decoded(dec, core), pcm) for dec in (
                        lambda o: dec_baac(ours, o, raw=True),
                        lambda o: dec_flac(ours, o, raw=True),
                        lambda o: dec_ffmpeg(ours, o, expected["bits"], raw=True),
                        lambda o: dec_baac(theirs, o, raw=True)))
                    a, b = flac_frame_bytes(ours), flac_frame_bytes(theirs)
                    totals[level]["baac"] += a
                    totals[level]["flac"] += b
                    suffix = "" if level == 5 else f"_{level}"
                    record[f"baac_frame_bytes{suffix}"] = a
                    record[f"flac_frame_bytes{suffix}"] = b
                record["bit_exact"] = bool(exact)
                records.append(record)
        gate(f"{prefix}-{set_id}-size", group, f"{what} {label}: level 5 frame bytes against flac -5",
             dict(kind="size", **size_verdict(totals[5]["baac"], totals[5]["flac"])))
        set_reps = long_input(d, f"{prefix}.{set_id}", set_raw, expected, core)
        lraw = d / f"{prefix}.{set_id}.long.raw"
        stream = d / f"{prefix}.{set_id}.long.flac"
        cpu_run(enc_flac(5, lraw, stream, expected), core)
        r = measure_gate(f"{prefix}-{set_id}-enc", [enc_baac(5, lraw, "/dev/null", expected)],
                         [enc_flac(5, lraw, "/dev/null", expected)], repeats, core)
        gate(f"{prefix}-{set_id}-enc", group, f"{what} {label}: encode at level 5 against flac -5",
             dict(kind="speed", **r), f"one process over the set repeated {set_reps} times, raw "
             "input")
        r = measure_gate(f"{prefix}-{set_id}-dec-flac", [dec_baac(stream, "/dev/null")],
                         [dec_flac(stream, "/dev/null")], repeats, core)
        gate(f"{prefix}-{set_id}-dec-flac", group,
             f"{what} {label}: decode of the flac -5 stream against flac (MD5 verified by both)",
             dict(kind="speed", **r), f"one process over the set repeated {set_reps} times")
        r = measure_gate(f"{prefix}-{set_id}-dec-ffmpeg", [dec_baac(stream, "/dev/null", md5=False)],
                         [dec_ffmpeg(stream, "/dev/null", expected["bits"])], repeats, core)
        gate(f"{prefix}-{set_id}-dec-ffmpeg", group,
             f"{what} {label}: decode of the flac -5 stream against FFmpeg (frame CRCs checked "
             "by both, no MD5)", dict(kind="speed", **r),
             f"one process over the set repeated {set_reps} times")
        for f in (lraw, stream, d / f"{prefix}.{set_id}.long.wav", set_raw,
                  d / f"{prefix}.one.raw", d / f"{prefix}.baac.flac", d / f"{prefix}.flac.flac"):
            f.unlink(missing_ok=True)
        return records, totals

    limited = bool(args.only or args.gates_only or args.any_flac or args.reps)

    # The corpus additions, and the totals of the enlarged corpus: the ten
    # items back to back and these recordings.
    added = []
    corpus_totals = dict(original={str(level): dict(baac=by[f"baac-{level}"]["frame_bytes"],
                                                    flac=by[f"flac-{level}"]["frame_bytes"])
                                   for level in (5, 8)})
    enlarged = {level: dict(corpus_totals["original"][str(level)]) for level in (5, 8)}
    measured_all = True
    for set_id, label, expected, sources in CORPUS_ADDITIONS:
        if not only.search(f"added-{set_id}"):
            measured_all = False
            continue
        if not (TESTBENCH / "subset").is_dir():
            raise SystemExit(f"the FLAC decoder testbench is missing at {TESTBENCH}: run "
                             "../tests/flac-testbench.sh")
        records, totals = measure_set("added", "corpus", "corpus addition", TESTBENCH / "subset",
                                      set_id, label, expected, sources, (5, 8))
        added += records
        for level in (5, 8):
            for side in ("baac", "flac"):
                enlarged[level][side] += totals[level][side]
    if measured_all:
        corpus_totals["enlarged"] = {str(level): enlarged[level] for level in (5, 8)}
        for level in (5, 8):
            gate(f"size-{level}-enlarged", "corpus",
                 f"bAAC level {level} frame bytes against flac -{level} on the enlarged corpus",
                 dict(kind="size", **size_verdict(enlarged[level]["baac"],
                                                  enlarged[level]["flac"])))

    # The held-out recordings.  Nothing before this point has read them,
    # and nothing reads them unless this is a run that can count: complete,
    # asked to judge its gates, and begun on a quiet host.
    held = []
    if limited or not args.check:
        held_run = dict(touched=False, reason="the run was limited" if limited else
                        "the stage was not run with --check")
    elif other_start > args.quiet_cpu:
        held_run = dict(touched=False, reason=f"the host was not quiet at the start (the rest "
                                              f"of it used {other_start:.2f} CPUs)")
    else:
        if not HELDOUT_DIR.is_dir():
            raise SystemExit(f"the held-out recordings are missing at {HELDOUT_DIR}: run "
                             "./fetch-heldout.sh")
        held_run = held_out_entry(HELDOUT_DIR / "runs.json", started,
                                  file_sha256(TOOLS["baac"]), git_state())
        for set_id, label, expected, sources in HELD_OUT:
            records, _ = measure_set("held", "held-out", "held-out", HELDOUT_DIR, set_id, label,
                                     expected, sources, (5,))
            held += records
    if not held_run["touched"]:
        print(f"the held-out recordings were not read: {held_run['reason']}", file=sys.stderr)

    other_run = other_cpu(start, cpu_sample())
    load_end = os.getloadavg()
    complete = not limited
    host = dict(host, other_cpu_start=round(other_start, 3), other_cpu_run=round(other_run, 3),
                quiet_cpu=args.quiet_cpu, load_start=[round(x, 2) for x in load_start],
                load_end=[round(x, 2) for x in load_end])
    host["quiet"] = quiet_verdict(host)[0]
    result = dict(
        schema=2, date=started, complete=complete, host=host,
        tools=dict(baac=git_state(), flac=flac_version, ffmpeg=tool_version([ff, "-version"]),
                   refalac="measured" if have("refalac") else "carried from 2026-10-30"),
        method=dict(pinned=f"taskset -c {core}", repeats=repeats, corpus_seconds=seconds,
                    corpus_repetitions=reps, min_cpu_seconds=MIN_CPU_SECONDS,
                    statistic="median of the paired ratios (bAAC CPU time over the reference's); "
                              "threshold 1 + (max - min) / median of the reference's times",
                    judged="long run (one process over the repeated input); encode from raw "
                           "PCM, which flac reads about 3 % faster than WAV"),
        rows=rows + alac, gates=gates, figures=figures, corpus_totals=corpus_totals,
        corpus_additions=added, held_out=held, held_out_run=held_run)
    (WORK / "lossless.json").write_text(json.dumps(result, indent=1) + "\n")
    for f in d.glob("*.long.*"):
        f.unlink()
    print(f"the rest of the host used {other_run:.2f} CPUs during the run, load {load_end[0]:.2f} "
          f"at the end; wrote {WORK / 'lossless.json'}", file=sys.stderr)
    if args.check:
        sys.exit(0 if check_lossless(result) else 1)


def check_lossless(result, out=sys.stdout):
    """Print every gate of a lossless run; True when all of them passed in
    a complete run on a quiet host."""
    ok = True
    host = result.get("host", {})
    print(f"lossless run of {result.get('date', '?')} on {host.get('cpu', '?')}, core "
          f"{host.get('core', '?')}, the rest of the host using {host.get('other_cpu_run', '?')} "
          f"CPUs during the run and {host.get('other_cpu_start', '?')} in the minute before it, "
          f"load {host.get('load_start', ['?'])[0]} at the start and "
          f"{host.get('load_end', ['?'])[0]} at the end", file=out)
    for g in result.get("gates", []):
        if g["kind"] == "size":
            detail = f"{g['ours']} bytes against {g['reference']}"
        else:
            detail = (f"ratio {g['ratio_median']:.4f} ({g['ratio_min']:.4f} to "
                      f"{g['ratio_max']:.4f}), threshold {g['threshold']:.4f}")
            if g.get("min_cpu", MIN_CPU_SECONDS) < MIN_CPU_SECONDS:
                detail += f", a run of only {g['min_cpu']:.2f} CPU s"
                g = dict(g, verdict="undecided")
        print(f"{g['verdict']:9} {g['group']} {g['id']}: {g['description']}: {detail}",
              file=out)
        ok = ok and g["verdict"] == "passed"
    for r in result.get("rows", []):
        if not r.get("bit_exact", False):
            print(f"failed    {r['codec']} is not bit-exact", file=out)
            ok = False
    for what, key in (("corpus addition", "corpus_additions"), ("held-out file", "held_out")):
        for h in result.get(key, []):
            if not h.get("bit_exact", False):
                print(f"failed    {what} \"{h['file']}\" is not bit-exact", file=out)
                ok = False
    # An observation, not a gate: each level against the level below it.
    sizes = {r["level"]: r["frame_bytes"] for r in result.get("rows", [])
             if r.get("encoder") == "baac"}
    larger = [f"{n} ({sizes[n]} against {sizes[n - 1]})" for n in sorted(sizes)
              if n - 1 in sizes and sizes[n] > sizes[n - 1]]
    print("observation: " + ("every bAAC level is no larger than the level below it" if not larger
                             else "bAAC levels larger than the level below: " + ", ".join(larger)),
          file=out)
    if not result.get("complete", False):
        print("not a gate result: the run was limited (--only, --gates-only, --reps or --any-flac)",
              file=out)
        ok = False
    else:
        present = {g["id"] for g in result.get("gates", [])}
        for gid in expected_gates():
            if gid not in present:
                print(f"failed    the gate {gid} is missing from the result", file=out)
                ok = False
    quiet, why = quiet_verdict(host)
    if not quiet:
        print(f"not a gate result: the host was not quiet ({why}); run it again when it is",
              file=out)
        ok = False
    # The held-out recordings count once: in their first run, or in a
    # later run of the build the first run measured.
    run = result.get("held_out_run", {})
    if not run.get("touched", False):
        print("not a gate result: the held-out recordings were not read ("
              f"{run.get('reason', 'the result does not say why')})", file=out)
        ok = False
    elif run.get("first_run", False):
        print("held-out recordings: this was their first run", file=out)
    elif run.get("same_build_as_first", False):
        print(f"held-out recordings: run {run.get('run')}, of the build their first run "
              f"measured on {run.get('first_date')}", file=out)
    else:
        print(f"not a gate result for the held-out recordings: run {run.get('run')}, and the "
              f"first, on {run.get('first_date')}, measured another build of baac", file=out)
        ok = False
    print("every gate passed" if ok else "not every gate passed", file=out)
    return ok


def stage_check(args):
    path = Path(args.results) if args.results else WORK / "lossless.json"
    if not path.exists():
        raise SystemExit(f"{path} is missing: run ./bench.py lossless first")
    sys.exit(0 if check_lossless(json.loads(path.read_text())) else 1)


def lossless_section(result):
    """The Lossless section of tables.md from a run's results."""
    md = ["## Lossless (generated)\n"]
    host, method, tools = result["host"], result["method"], result["tools"]
    noisy = "" if quiet_verdict(host)[0] else ": the host was not quiet, so this is not a gate result"
    md.append(f"Run of {result['date']} on {host['cpu']} (core {host['core']}, "
              f"{host['compiler'] or 'unknown compiler'}). The rest of the host used "
              f"{host['other_cpu_run']:.2f} CPUs on average during the run and "
              f"{host['other_cpu_start']:.2f} in the minute before it (load average "
              f"{host['load_start'][0]} at the start and {host['load_end'][0]} at the end){noisy}"
              f". bAAC {tools['baac']}, {tools['flac']}, {tools['ffmpeg'].split(' Copyright')[0]}; "
              f"refalac {tools['refalac']}. The corpus is {method['corpus_seconds']:.1f} s; timed "
              f"runs repeat it {method['corpus_repetitions']} times in one process pinned with "
              f"`{method['pinned']}`, {method['repeats']} repeats with the reference and bAAC "
              "alternated.\n")
    md.append("| Codec | kbit/s | Frame bytes | Enc × realtime | Dec × realtime | Bit-exact |")
    md.append("|---|--:|--:|--:|--:|---|")
    for r in result["rows"]:
        enc = f"{r['seconds'] / r['enc_cpu']:.0f}" if r.get("enc_cpu") else "-"
        dec = f"{r['seconds'] / r['dec_cpu']:.0f}" if r.get("dec_cpu") else "-"
        name = r["codec"] + (f" (carried from {r['carried']})" if r.get("carried") else "")
        md.append(f"| {name} | {r['kbps']:.2f} | {r.get('frame_bytes', '-')} | {enc} | {dec} | "
                  f"{r['bit_exact']} |")
    md.append("\n### Gates\n")
    md.append("A speed ratio is bAAC's CPU time over the reference's, the median of the paired "
              "repeats with their range; the threshold is 1 plus the reference's own spread. "
              f"Judged on: {method['judged']}.\n")
    md.append("| Gate | Group | bAAC | Reference | Ratio (range) | Threshold | Verdict |")
    md.append("|---|---|--:|--:|--:|--:|---|")
    for g in result["gates"]:
        if g["kind"] == "size":
            md.append(f"| {g['id']}: {g['description']} | {g['group']} | {g['ours']} | "
                      f"{g['reference']} | | | {g['verdict']} |")
        else:
            md.append(f"| {g['id']}: {g['description']} | {g['group']} | "
                      f"{g['ours_median']:.3f} s | {g['reference_median']:.3f} s | "
                      f"{g['ratio_median']:.3f} ({g['ratio_min']:.3f} to {g['ratio_max']:.3f}) | "
                      f"{g['threshold']:.3f} | {g['verdict']} |")
    if result["figures"]:
        md.append("\n### Every figure of the speed gates\n")
        md.append("Long: one process over the repeated corpus. Per file: one process per corpus "
                  "item, which adds each tool's start. The judged figure is marked.\n")
        md.append("| Measure | Variant | bAAC s | Reference s | Ratio (range) | Threshold | Reads as |")
        md.append("|---|---|--:|--:|--:|--:|---|")
        for f in result["figures"]:
            md.append(f"| {f['measure']} | {f['variant']}{' (judged)' if f['judged'] else ''} | "
                      f"{f['ours_median']:.3f} | {f['reference_median']:.3f} | "
                      f"{f['ratio_median']:.3f} ({f['ratio_min']:.3f} to {f['ratio_max']:.3f}) | "
                      f"{f['threshold']:.3f} | {f['verdict']} |")
    totals = result.get("corpus_totals", {})
    if totals:
        md.append("\n### Corpus totals, frame bytes\n")
        md.append("The original corpus is the ten items back to back. The enlarged corpus adds "
                  "the recordings of the first held-out set, which are listed below.\n")
        md.append("| Corpus | Level | bAAC | flac |")
        md.append("|---|--:|--:|--:|")
        for name in ("original", "enlarged"):
            for level, t in sorted(totals.get(name, {}).items()):
                md.append(f"| {name} | {level} | {t['baac']} | {t['flac']} |")
    if result.get("corpus_additions"):
        md.append("\n### Corpus additions\n")
        md.append("| Set | File | Seconds | bAAC level 5 | flac -5 | bAAC level 8 | flac -8 | "
                  "Bit-exact |")
        md.append("|---|---|--:|--:|--:|--:|--:|---|")
        for h in result["corpus_additions"]:
            md.append(f"| {h['set']} | {h['file']} | {h['seconds']:.1f} | {h['baac_frame_bytes']} | "
                      f"{h['flac_frame_bytes']} | {h['baac_frame_bytes_8']} | "
                      f"{h['flac_frame_bytes_8']} | {h['bit_exact']} |")
    run = result.get("held_out_run", {})
    md.append("\n### Held-out recordings, level 5\n")
    if not run.get("touched", False):
        md.append(f"Not read in this run: {run.get('reason', 'the result does not say why')}.")
    else:
        md.append("This was the first run on these recordings." if run.get("first_run") else
                  f"Run {run.get('run')} on these recordings; the first was on "
                  f"{run.get('first_date')}"
                  + (" and measured this build of baac." if run.get("same_build_as_first") else
                     " and measured another build of baac, so this run is not a gate result "
                     "for them."))
        md.append("\n| Set | File | Span | Seconds | bAAC frame bytes | flac -5 frame bytes | "
                  "Bit-exact |")
        md.append("|---|---|---|--:|--:|--:|---|")
        for h in result.get("held_out", []):
            span = " to ".join(h["span"]) if h.get("span") else "whole"
            md.append(f"| {h['set']} | {h['file']} | {span} | {h['seconds']:.1f} | "
                      f"{h['baac_frame_bytes']} | {h['flac_frame_bytes']} | {h['bit_exact']} |")
    return md



# ---------------------------------------------------------------- report

T95 = {9: 2.262, 8: 2.306, 7: 2.365, 6: 2.447, 5: 2.571, 4: 2.776}


def paired(ours, theirs):
    """Mean of theirs - ours over the shared items and its 95 % interval."""
    keys = sorted(set(ours) & set(theirs))
    if len(keys) < 5:
        return None, None
    dif = np.array([theirs[k] - ours[k] for k in keys])
    half = T95.get(len(keys) - 1, 2.0) * dif.std(ddof=1) / np.sqrt(len(keys))
    return float(dif.mean()), float(half)


def fmt(v, nd=2):
    return "-" if v is None or (isinstance(v, float) and np.isnan(v)) else f"{v:.{nd}f}"


SECTIONS = ["Quality", "Speed and memory", "Decoder agreement", "Lossless"]


def split_sections(text):
    """The generated sections of tables.md, by title, each without the
    blank lines around it."""
    out = {}
    starts = [m.start() for m in re.finditer(r"(?m)^## ", text)] + [len(text)]
    for a, b in zip(starts, starts[1:]):
        chunk = text[a:b].strip("\n")
        title = chunk.split("\n", 1)[0][3:].replace(" (generated)", "").strip()
        out[title] = chunk
    return out


def merge_sections(old_text, new):
    """tables.md with the sections in `new` replaced and every other
    section as it was, byte for byte."""
    sections = split_sections(old_text)
    sections.update({title: "\n".join(lines).strip("\n") for title, lines in new.items()})
    order = [t for t in SECTIONS if t in sections] + [t for t in sections if t not in SECTIONS]
    return "\n\n".join(sections[t] for t in order) + "\n"


def stage_report(args):
    """Writes the results of every stage that has a cache; a stage without
    one keeps its results files and its section of tables.md as they are."""
    RESULTS.mkdir(parents=True, exist_ok=True)
    rows = [json.loads(p.read_text()) for p in sorted((WORK / "quality").glob("*/*.json"))]
    new = {}
    md = []
    if rows:
        keys = ["config", "family", "encoder", "mode", "nominal", "item", "seconds", "bytes",
                "kbps", "delay", "gain_db", "lowband_corr", "enc_cpu", "enc_wall", "enc_rss_kb",
                "peaq_basic", "peaq_adv", "visqol"]
        with open(RESULTS / "quality.csv", "w", newline="") as f:
            w = csv.DictWriter(f, fieldnames=keys, extrasaction="ignore")
            w.writeheader()
            for r in rows:
                w.writerow({k: (round(v, 4) if isinstance(v, float) else v) for k, v in r.items()})
        by = {}
        for r in rows:
            by.setdefault(r["config"], []).append(r)
        order = [c["id"] for c in cfg_list() if c["id"] in by]
        summary = []
        for cid in order:
            rs = by[cid]
            def col(k):
                v = [r[k] for r in rs if r.get(k) is not None]
                return np.array(v) if v else None
            pb, pa, vq = col("peaq_basic"), col("peaq_adv"), col("visqol")
            # Paired against our configuration of the same family and nominal rate.
            ours_id = re.sub(r"-(oursab|oursbw|ffmpeg|fdknoab|fdk|libxaac|apple)-", "-ours-", cid)
            dpb = dvq = (None, None)
            if ours_id != cid and ours_id in by:
                o = {r["item"]: r for r in by[ours_id]}
                t = {r["item"]: r for r in rs}
                dpb = paired({k: v["peaq_basic"] for k, v in o.items()},
                             {k: v["peaq_basic"] for k, v in t.items()})
                if vq is not None:
                    dvq = paired({k: v["visqol"] for k, v in o.items() if v.get("visqol")},
                                 {k: v["visqol"] for k, v in t.items() if v.get("visqol")})
            summary.append(dict(config=cid, family=rs[0]["family"], encoder=rs[0]["encoder"],
                                mode=rs[0]["mode"], nominal=rs[0]["nominal"], items=len(rs),
                                kbps=float(col("kbps").mean()),
                                peaq_basic=float(pb.mean()), peaq_basic_min=float(pb.min()),
                                peaq_adv=float(pa.mean()),
                                visqol=float(vq.mean()) if vq is not None else None,
                                visqol_min=float(vq.min()) if vq is not None else None,
                                d_peaq_vs_ours=dpb[0], d_peaq_ci=dpb[1],
                                d_visqol_vs_ours=dvq[0], d_visqol_ci=dvq[1],
                                enc_x_realtime=float(col("seconds").sum() / col("enc_cpu").sum()),
                                delay=int(np.median(col("delay"))),
                                gain_db_max=float(np.abs(col("gain_db")).max())))
        with open(RESULTS / "quality_summary.csv", "w", newline="") as f:
            w = csv.DictWriter(f, fieldnames=list(summary[0].keys()))
            w.writeheader()
            for r in summary:
                w.writerow({k: (round(v, 4) if isinstance(v, float) else v) for k, v in r.items()})
        md.append("## Quality (generated)\n")
        md.append("PEAQ ODG: 0 = imperceptible, -1 perceptible but not annoying, -2 slightly "
                  "annoying, -3 annoying, -4 very annoying. ViSQOL MOS-LQO: 1 to 5 (about 4.7 at "
                  "best). Δ = their mean minus ours over the ten items, ± the 95 % interval of "
                  "the paired difference; positive means they score higher.\n")
        fam = None
        for r in summary:
            if r["family"] != fam:
                fam = r["family"]
                md.append(f"\n### {fam}\n")
                md.append("| Configuration | kbit/s | PEAQ mean | PEAQ worst | PEAQ adv | ΔPEAQ vs ours | ViSQOL | ViSQOL worst | ΔViSQOL vs ours |")
                md.append("|---|--:|--:|--:|--:|--:|--:|--:|--:|")
            d1 = "" if r["d_peaq_vs_ours"] is None else f"{r['d_peaq_vs_ours']:+.2f} ± {r['d_peaq_ci']:.2f}"
            d2 = "" if r["d_visqol_vs_ours"] is None else f"{r['d_visqol_vs_ours']:+.2f} ± {r['d_visqol_ci']:.2f}"
            md.append(f"| {r['config']} | {r['kbps']:.1f} | {fmt(r['peaq_basic'])} | {fmt(r['peaq_basic_min'])} | "
                      f"{fmt(r['peaq_adv'])} | {d1} | {fmt(r['visqol'])} | {fmt(r['visqol_min'])} | {d2} |")

        # Equal-bitrate comparison: each item's metric interpolated along
        # its encoder's rate curve (CBR points, or the VBR points) at fixed
        # rates inside every curve's measured range.
        grids = {"lc": (80, 112, 160, 224), "lcvbr": (112, 128, 160), "he": (40, 56),
                 "hev2": (28,), "xhe": (32, 48, 64, 80)}
        md.append("\n### At equal bitrate (interpolated)\n")
        md.append("Each item's score interpolated linearly in bitrate between the encoder's "
                  "neighbouring measured points; Δ against ours at the same rate, ± 95 % "
                  "interval of the paired difference over the ten items. AAC-LC VBR rows "
                  "interpolate along each encoder's VBR points.\n")
        md.append("| Family | kbit/s | Encoder | PEAQ | ΔPEAQ vs ours | ViSQOL | ΔViSQOL vs ours |")
        md.append("|---|--:|---|--:|--:|--:|--:|")
        curves = {}
        for r in rows:
            curves.setdefault((r["family"], r["encoder"], r["item"]), []).append(r)
        def interp(fam, enc, item, rate, metric):
            pts = sorted((x["kbps"], x.get(metric)) for x in curves.get((fam, enc, item), [])
                         if x.get(metric) is not None)
            if len(pts) < 2 or not (pts[0][0] <= rate <= pts[-1][0]):
                return None
            return float(np.interp(rate, [p[0] for p in pts], [p[1] for p in pts]))
        encs = ["ours", "oursnoab", "oursbw", "ffmpeg", "fdk", "fdknoab", "libxaac", "apple"]
        for fam, rates in grids.items():
            for rate in rates:
                base = {m: {i: interp(fam, "ours", i, rate, m) for i in MUSIC}
                        for m in ("peaq_basic", "visqol")}
                for enc in encs:
                    vals = {m: {i: interp(fam, enc, i, rate, m) for i in MUSIC}
                            for m in ("peaq_basic", "visqol")}
                    pv = [v for v in vals["peaq_basic"].values() if v is not None]
                    if len(pv) < 5:
                        continue
                    vv = [v for v in vals["visqol"].values() if v is not None]
                    cells = []
                    for m in ("peaq_basic", "visqol"):
                        if enc == "ours":
                            cells.append("")
                            continue
                        dm, ci = paired({k: v for k, v in base[m].items() if v is not None},
                                        {k: v for k, v in vals[m].items() if v is not None})
                        cells.append("" if dm is None else f"{dm:+.2f} ± {ci:.2f}")
                    md.append(f"| {fam} | {rate} | {enc} | {np.mean(pv):.2f} | {cells[0]} | "
                              f"{fmt(float(np.mean(vv)) if vv else None)} | {cells[1]} |")
        # Per-item PEAQ for the main LC point, to show where we lose.
        md.append("\n### Per item, AAC-LC 128 kbit/s CBR (PEAQ basic / ViSQOL)\n")
        cols = [c for c in order if re.match(r"lc-.*-cbr128$", c)]
        md.append("| Item | " + " | ".join(c.split("-")[1] for c in cols) + " |")
        md.append("|---|" + "--:|" * len(cols))
        for item in MUSIC:
            cells = []
            for c in cols:
                r = next((x for x in by[c] if x["item"] == item), None)
                cells.append("-" if r is None else f"{r['peaq_basic']:.2f} / {fmt(r.get('visqol'))}")
            md.append(f"| {item.replace('48_stereo', '')} | " + " | ".join(cells) + " |")
        new["Quality"] = md
    sp = WORK / "speed.json"
    if sp.exists():
        srows = json.loads(sp.read_text())
        (RESULTS / "speed.json").write_text(json.dumps(srows, indent=1))
        md = new["Speed and memory"] = ["## Speed and memory (generated)\n"]
        md.append("CPU seconds (user + system, best of the runs) over the "
                  f"{srows[-1]['seconds']:.0f} s corpus concatenation; × realtime = audio "
                  "seconds per CPU second; RSS = peak resident set of the process.\n")
        md.append("| Kind | Family | Codec | × realtime | CPU s | Peak RSS MiB |")
        md.append("|---|---|---|--:|--:|--:|")
        for r in srows:
            if "error" in r:
                md.append(f"| {r['kind']} | {r['family']} | {r['codec']} | failed | | |")
                continue
            x = r["seconds"] / r["cpu"] if r["seconds"] and r["cpu"] else float("nan")
            md.append(f"| {r['kind']} | {r['family']} | {r['codec']} | {fmt(x, 0)} | "
                      f"{r['cpu']:.2f} | {r['rss_kb'] / 1024:.1f} |")
    cp = WORK / "compat.json"
    if cp.exists():
        crows = json.loads(cp.read_text())
        (RESULTS / "compat.json").write_text(json.dumps(crows, indent=1))
        md = new["Decoder agreement"] = ["## Decoder agreement (generated)\n"]
        md.append("Each decode aligned to the source; SNR of every decoder's output against "
                  "our decoder's (higher = closer; about 130 dB is float rounding). Min over the "
                  "ten items.\n")
        md.append("| Stream | Decoder | Decoded | Min SNR vs ours dB | Median SNR vs ours dB |")
        md.append("|---|---|--:|--:|--:|")
        agg = {}
        for r in crows:
            agg.setdefault((r["config"], r["decoder"]), []).append(r)
        for (cid, dec), rs in agg.items():
            if dec == "ours":
                continue
            ok = [r for r in rs if r["ok"]]
            v = [r["snr_vs_ours"] for r in ok if r.get("snr_vs_ours") is not None]
            md.append(f"| {cid} | {dec} | {len(ok)}/{len(rs)} | {fmt(min(v), 1) if v else '-'} | "
                      f"{fmt(float(np.median(v)), 1) if v else '-'} |")
    lp = WORK / "lossless.json"
    if lp.exists():
        result = json.loads(lp.read_text())
        (RESULTS / "lossless.json").write_text(json.dumps(result, indent=1) + "\n")
        new["Lossless"] = lossless_section(result)
    tables = RESULTS / "tables.md"
    tables.write_text(merge_sections(tables.read_text() if tables.exists() else "", new))
    print(f"wrote {', '.join(new) or 'nothing'} under {RESULTS}", file=sys.stderr)


def stage_selftest(args):
    """The harness's own logic on made-up figures: no codec runs."""
    failures = []

    def expect(cond, what):
        print(("ok    " if cond else "FAIL  ") + what)
        if not cond:
            failures.append(what)

    # The statistic.  A reference spread of 1 % makes the threshold 1.01.
    refs = [3.000, 3.030, 3.015, 3.000, 3.006]
    below = speed_verdict([(r * x, r) for r, x in zip(refs, (0.97, 0.98, 0.99, 1.00, 0.985))])
    straddle = speed_verdict([(r * x, r) for r, x in zip(refs, (0.99, 1.03, 1.00, 1.02, 0.995))])
    above = speed_verdict([(r * x, r) for r, x in zip(refs, (1.02, 1.05, 1.03, 1.04, 1.025))])
    expect(abs(below["threshold"] - 1.00998) < 1e-4, "a reference spread of 1 % gives a threshold of 1.01")
    expect(below["verdict"] == "passed", "paired ratios of 0.97 to 1.00 pass")
    expect(straddle["verdict"] == "undecided", "paired ratios of 0.99 to 1.03 are undecided")
    expect(above["verdict"] == "failed", "paired ratios of 1.02 to 1.05 fail")
    short = speed_verdict([(r * x / 2, r / 2) for r, x in zip(refs, (0.97, 0.98, 0.99, 1.00, 0.985))])
    expect(short["verdict"] == "undecided", "the same ratios from runs under 2 CPU seconds are undecided")
    expect(size_verdict(1000, 1000)["verdict"] == "passed", "a size equal to the reference passes")
    expect(size_verdict(1001, 1000)["verdict"] == "failed", "one byte more than the reference fails")

    # The check mode on a whole made-up run.
    def made_up(speed, size=(1000, 1000), other=(0.2, 0.3), exact=True, held=None):
        host = dict(cpu="test", core=0, other_cpu_start=other[0], other_cpu_run=other[1],
                    quiet_cpu=QUIET_CPU, load_start=[2.5], load_end=[2.5])
        host["quiet"] = quiet_verdict(host)[0]
        return dict(date="test", complete=True, host=host, held_out=[],
                    held_out_run=held or dict(touched=True, run=1, first_run=True,
                                              same_build_as_first=True, first_date="test"),
                    rows=[dict(codec="baac-5", encoder="baac", level=5, frame_bytes=10,
                               bit_exact=exact)],
                    gates=[dict(id="size-5", group="flac", description="size", kind="size",
                                **size_verdict(*size)),
                           dict(id="enc-5", group="flac", description="speed", kind="speed",
                                **speed)]
                    + [dict(id=gid, group="test", description="made up", kind="size",
                            **size_verdict(1000, 1000))
                       for gid in expected_gates() if gid not in ("size-5", "enc-5")])
    sink = open(os.devnull, "w")
    expect(check_lossless(made_up(below), sink), "the check passes a run whose gates all passed")
    expect(not check_lossless(made_up(straddle), sink), "the check does not pass an undecided gate")
    expect(not check_lossless(made_up(above), sink), "the check does not pass a failed gate")
    expect(not check_lossless(made_up(below, size=(1001, 1000)), sink),
           "the check does not pass a row one byte larger than its reference")
    expect(not check_lossless(made_up(below, other=(0.2, 2.5)), sink),
           "the check does not pass a run during which the rest of the host used 2.5 CPUs")
    expect(not check_lossless(made_up(below, other=(2.5, 0.3)), sink),
           "the check does not pass a run that began while the rest of the host used 2.5 CPUs")
    expect(check_lossless(made_up(below, other=(2.0, 2.0)), sink),
           "the check passes a run with the rest of the host at 2.0 CPUs, whatever the load average")
    unmeasured = made_up(below)
    for key in ("other_cpu_start", "other_cpu_run"):
        del unmeasured["host"][key]
    expect(not check_lossless(unmeasured, sink),
           "the check does not pass a result that does not measure the rest of the host")

    # The share of the rest of the host.  At 100 ticks a second, 4,200
    # busy ticks are 42 CPU seconds; the run used 12 of them over 20 s, so
    # the rest of the host used 1.5 CPUs.  Idle and iowait are not busy.
    stat_a = "cpu  1000 0 500 90000 300 0 0 0 0 0\ncpu0 1 2 3 4\n"
    stat_b = "cpu  4000 0 1500 99000 900 100 100 0 0 0\ncpu0 1 2 3 4\n"
    a = dict(busy=busy_seconds(stat_a, 100), own=3.0, clock=100.0)
    b = dict(busy=busy_seconds(stat_b, 100), own=15.0, clock=120.0)
    expect(abs(busy_seconds(stat_b, 100) - busy_seconds(stat_a, 100) - 42.0) < 1e-9,
           "the busy time of /proc/stat counts user, nice, system, irq and softirq")
    expect(abs(other_cpu(a, b) - 1.5) < 1e-9, "the rest of the host used 1.5 CPUs")
    expect(quiet_verdict(dict(other_cpu_start=0.4, other_cpu_run=other_cpu(a, b)))[0],
           "1.5 CPUs is quiet")
    busier = dict(b, busy=b["busy"] + 20.0)
    expect(abs(other_cpu(a, busier) - 2.5) < 1e-9 and
           not quiet_verdict(dict(other_cpu_start=0.4, other_cpu_run=other_cpu(a, busier)))[0],
           "20 busy CPU seconds more make 2.5 CPUs, which is not quiet")
    expect(not check_lossless(made_up(below, exact=False), sink),
           "the check does not pass a row that is not bit-exact")
    expect(not check_lossless(made_up(below, held=dict(touched=False, reason="test")), sink),
           "the check does not pass a run that did not read the held-out recordings")
    for lost in ("dec-8-ffmpeg", "held-surround-enc", "size-8-enlarged"):
        short = made_up(below)
        short["gates"] = [g for g in short["gates"] if g["id"] != lost]
        expect(not check_lossless(short, sink),
               f"the check does not pass a complete run without the gate {lost}")

    # The ledger of the held-out recordings: the first run, a second run
    # of the same build, and a run of another build.
    with tempfile.TemporaryDirectory() as tmp:
        ledger = Path(tmp) / "heldout" / "runs.json"
        first = held_out_entry(ledger, "day 1", "aaaa", "rev1")
        again = held_out_entry(ledger, "day 2", "aaaa", "rev1")
        other = held_out_entry(ledger, "day 3", "bbbb", "rev2")
        expect(first["first_run"] and first["run"] == 1, "the ledger books a first run as the first")
        expect(not again["first_run"] and again["same_build_as_first"] and again["run"] == 2
               and again["first_date"] == "day 1",
               "a second run of the same build is booked as a repeat")
        expect(not other["first_run"] and not other["same_build_as_first"] and other["run"] == 3,
               "a run of another build is booked as such")
        expect(len(json.loads(ledger.read_text())["runs"]) == 3, "the ledger keeps every run")
        expect(check_lossless(made_up(below, held=again), sink),
               "the check passes a repeat on the build the first run measured")
        expect(not check_lossless(made_up(below, held=other), sink),
               "the check does not pass the held-out gates of another build's later run")

    # Exactness: one sample altered is not the same decode.
    pcm = bytes(range(256)) * 16
    altered = bytearray(pcm)
    altered[1000] ^= 1
    expect(same_bytes(pcm, bytes(pcm)) and not same_bytes(pcm, bytes(altered)),
           "a decode altered by one sample is not bit-exact")

    # The locale every tool inherits.
    child = subprocess.run([sys.executable, "-c", "import os; print(os.environ.get('LC_ALL'))"],
                           stdout=subprocess.PIPE).stdout.decode().strip()
    expect(child == "C", "a tool the harness starts runs in the C locale")

    # The carried refalac row.
    measured = [dict(codec="alac-ffmpeg", kbps=697.0, seconds=130.3, bit_exact=True)]
    carried = alac_rows(False, measured)
    expect(len(carried) == 2 and carried[1].get("carried") == "2026-10-30" and
           "enc_cpu" not in carried[1],
           "without refalac its row is the carried one, marked and without speed")
    own = alac_rows(True, measured + [dict(codec="alac-apple-ref", kbps=690.0, seconds=130.3,
                                           bit_exact=True)])
    expect(len(own) == 2 and "carried" not in own[1], "with refalac its measured row is used")

    # A held-out file of another format.
    try:
        check_held_out_format("28 - high resolution audio",
                              dict(sample_rate=48000, channels=2, bits=24),
                              dict(sample_rate=96000, channels=2, bits=24))
        expect(False, "a held-out file of another format stops the stage")
    except SystemExit as e:
        expect("28 - high resolution audio" in str(e) and "48000" in str(e),
               "a held-out file of another format stops the stage with a message naming it")

    # The report: only the sections that have a cache change.
    committed = (HERE / "results" / "tables.md").read_text()
    expect(merge_sections(committed, {}) == committed,
           "tables.md comes back byte for byte when no section is replaced")
    global WORK, RESULTS
    keep = WORK, RESULTS
    with tempfile.TemporaryDirectory() as tmp:
        WORK, RESULTS = Path(tmp) / "work", Path(tmp) / "results"
        WORK.mkdir()
        shutil.copytree(HERE / "results", RESULTS)
        before = {p.name: p.read_bytes() for p in RESULTS.iterdir()}
        run_of = made_up(below)
        run_of.update(tools=dict(baac="x", flac="flac 1.4.3", ffmpeg="ffmpeg", refalac="carried"),
                      method=dict(corpus_seconds=130.3, corpus_repetitions=25, pinned="taskset -c 0",
                                  repeats=5, judged="long run"), figures=[])
        run_of["host"].update(compiler="c++")
        run_of["rows"][0].update(seconds=130.3, kbps=600.0, enc_cpu=0.2, dec_cpu=0.1)
        for g in run_of["gates"]:
            g.setdefault("ours_median", 1.0)
        (WORK / "lossless.json").write_text(json.dumps(run_of))
        stage_report(args)
        after = {p.name: p.read_bytes() for p in RESULTS.iterdir()}
        old, new = split_sections(before["tables.md"].decode()), split_sections(after["tables.md"].decode())
        expect(all(old[t] == new[t] for t in ("Quality", "Speed and memory", "Decoder agreement")),
               "with only the lossless cache, the other sections of tables.md are unchanged")
        expect(old.get("Lossless") != new["Lossless"] and "baac-5" in new["Lossless"] and
               list(new) == [t for t in SECTIONS if t in new],
               "and the Lossless section is the new one, in its place")
        expect(all(before[n] == after[n] for n in before if n not in ("tables.md", "lossless.json")),
               "and the other results files are byte-identical")
    WORK, RESULTS = keep
    print(f"selftest: {len(failures)} failure(s)")
    sys.exit(1 if failures else 0)


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("stage", choices=["quality", "speed", "compat", "lossless", "check", "report",
                                      "selftest", "list"])
    ap.add_argument("--only", help="regular expression over configuration ids (lossless: over "
                                   "gate ids; a limited run is not a gate result)")
    ap.add_argument("--jobs", type=int, default=os.cpu_count() or 1)
    ap.add_argument("--repeat", type=int, default=None,
                    help="timing repeats (lossless: at least 5, the default; other stages 3)")
    ap.add_argument("--check", action="store_true",
                    help="lossless: print the gates after the run and exit by them")
    ap.add_argument("--core", type=int, default=None,
                    help="lossless: the core to pin to (default: the idlest)")
    ap.add_argument("--reps", type=int, default=None,
                    help="lossless: corpus repetitions of a timed run (default: enough for "
                         "2 CPU seconds)")
    ap.add_argument("--gates-only", action="store_true",
                    help="lossless: skip the speed of the rows that carry no gate")
    ap.add_argument("--any-flac", action="store_true",
                    help="lossless: take a flac that is not the pinned 1.4.3")
    ap.add_argument("--quiet-cpu", type=float, default=QUIET_CPU,
                    help="lossless: the CPUs' worth the rest of the host may use, in the minute "
                         "before a run and over it, for the host to count as quiet")
    ap.add_argument("--results", help="check: a lossless.json other than the work cache")
    args = ap.parse_args()
    if args.stage == "list":
        for c in cfg_list():
            print(c["id"], "needs", ",".join(c["needs"]), "" if all(map(have, c["needs"])) else "(missing)")
        return
    if args.stage in ("quality", "speed", "compat") and sf is None:
        raise SystemExit(f"the {args.stage} stage needs the Python modules scipy and soundfile")
    if args.repeat is None:
        args.repeat = 5 if args.stage == "lossless" else 3
    {"quality": stage_quality, "speed": stage_speed, "compat": stage_compat,
     "lossless": stage_lossless, "check": stage_check, "report": stage_report,
     "selftest": stage_selftest}[args.stage](args)


if __name__ == "__main__":
    main()
