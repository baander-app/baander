#!/usr/bin/env python3
"""Codec comparison harness for Bånder Audio Core.

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
  lossless Bånder Audio Core's lossless mode against FLAC and ALAC on the same corpus
           and on held-out recordings: size, speed and bit-exactness,
           with the gates of the lossless mode (see lossless.py)
  check    print every lossless gate of the cached run as passed, failed
           or undecided, and exit non-zero unless all of them passed
  report   write the CSV files and the Markdown tables under results/;
           only the sections and files whose stage has a cache change
  selftest check the harness's own logic on made-up figures

Tool paths come from environment variables (see TOOLS in common.py); a
missing tool skips the configurations that need it and says so.
"""
import argparse
import csv
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np

try:
    import soundfile as sf
    from scipy import signal
except ImportError:  # the lossless, check and report stages do not need them
    sf = signal = None

from common import HERE, MUSIC, TOOLS, WORK, have, run, wine
from lossless import (QUIET_CPU, alac_rows, busy_seconds, check_held_out_format, check_lossless,
                      expected_gates, held_out_entry, lossless_section, other_cpu, quiet_verdict,
                      same_bytes, size_verdict, speed_verdict, stage_check, stage_lossless)
from rename_ledger import OLD_KEY, rename_ledger

RESULTS = Path(os.environ.get("BENCH_RESULTS", HERE / "results"))

SPEECH = ["CA01_01"]

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
        add("lc", "ours", k, "cbr", "aac", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "lc"])
        if k in BW_FDK:
            # Experiment: our encoder with the lowpass FDK's streams show.
            add("lc", "oursbw", k, "cbr", "aac", ["bac"],
                lambda s, f, o, b=b, k=k: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr",
                                           "0", "lc", "--bandwidth", str(BW_FDK[k])])
        add("lc", "oursnoab", k, "cbr", "aac", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "lc",
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
        add("lcvbr", "ours", m, "vbr", "aac", ["bac"],
            lambda s, f, o, m=m: [TOOLS["bac"], "enc", f, o, "48000", "2", "128000", "cbr", "0",
                                  "lc", "--vbr-mode", str(m)])
        add("lcvbr", "fdk", m, "vbr", "aac", ["fdk_enc"],
            lambda s, f, o, m=m: [TOOLS["fdk_enc"], "-t", "2", "-v", str(m), s, o])
    for q in (45, 63, 82, 100):
        add("lcvbr", "apple", q, "tvbr", "aac", ["qaac"],
            lambda s, f, o, q=q: wine("qaac") + ["--silent", "--adts", "-V", str(q), "-o", o, s])

    # HE-AAC v1.
    for k in (32, 48, 64):
        b = k * 1000
        add("he", "ours", k, "cbr", "aac", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "he"])
        add("he", "oursnoab", k, "cbr", "aac", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "he",
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
        add("hev2", "ours", k, "cbr", "aac", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "hev2"])
        add("hev2", "fdk", k, "cbr", "aac", ["fdk_enc"],
            lambda s, f, o, b=b: [TOOLS["fdk_enc"], "-r", str(b), "-t", "29", s, o])
        add("hev2", "libxaac", k, "cbr", "aac", ["xaac_enc"],
            lambda s, f, o, b=b: [TOOLS["xaac_enc"], f"-ifile:{s}", f"-ofile:{o}", "-aot:29",
                                  f"-br:{b}", "-adts:1"])

    # xHE-AAC (USAC).  Our encoder and FDK's write LOAS, libxaac a raw
    # elementary stream with a side file of access-unit sizes.
    for k in (24, 32, 48, 64, 96):
        b = k * 1000
        add("xhe", "ours", k, "cbr", "loas", ["bac"],
            lambda s, f, o, b=b: [TOOLS["bac"], "enc", f, o, "48000", "2", str(b), "cbr", "0", "xhe"])
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
    if have("bac"):
        out.append(("ours", lambda o: [TOOLS["bac"], "dec", stream, o, "48000", "0",
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
                    rows=[dict(codec="bac-5", encoder="bac", level=5, frame_bytes=10,
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
    stat_b = "cpu  3900 0 1500 99000 900 100 100 100 0 0\ncpu0 1 2 3 4\n"
    a = dict(busy=busy_seconds(stat_a, 100), own=3.0, clock=100.0)
    b = dict(busy=busy_seconds(stat_b, 100), own=15.0, clock=120.0)
    expect(abs(busy_seconds(stat_b, 100) - busy_seconds(stat_a, 100) - 42.0) < 1e-9,
           "the busy time of /proc/stat counts user, nice, system, irq, softirq and steal")
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

    # The ledger of the held-out recordings: a run of an earlier set
    # booked without its files' digests, then on one set the first run, a
    # second run of the same build and a run of another build, a run on a
    # new set, and a run on a set that shares a file with the first.
    with tempfile.TemporaryDirectory() as tmp:
        ledger = Path(tmp) / "heldout" / "runs.json"
        ledger.parent.mkdir()
        ledger.write_text(json.dumps(dict(runs=[dict(date="day 0", bac_sha256="0000",
                                                     git="rev0")])))
        first = held_out_entry(ledger, ["a1", "a2"], "day 1", "aaaa", "rev1")
        again = held_out_entry(ledger, ["a2", "a1"], "day 2", "aaaa", "rev1")
        other = held_out_entry(ledger, ["a1", "a2"], "day 3", "bbbb", "rev2")
        fresh = held_out_entry(ledger, ["b1", "b2"], "day 4", "bbbb", "rev2")
        shared = held_out_entry(ledger, ["a2", "c1"], "day 5", "bbbb", "rev2")
        expect(first["first_run"] and first["run"] == 1,
               "the ledger books a first run as the first, whatever runs an earlier set had")
        expect(not again["first_run"] and again["same_build_as_first"] and again["run"] == 2
               and again["first_date"] == "day 1",
               "a second run of the same build is booked as a repeat")
        expect(not other["first_run"] and not other["same_build_as_first"] and other["run"] == 3,
               "a run of another build is booked as such")
        expect(fresh["first_run"] and fresh["run"] == 1 and fresh["first_date"] == "day 4",
               "a run on a new set of recordings is booked as that set's first")
        expect(not shared["first_run"] and not shared["same_build_as_first"]
               and shared["first_date"] == "day 1",
               "a set that shares a file with an earlier set is not booked as a first run")
        expect(len(json.loads(ledger.read_text())["runs"]) == 6, "the ledger keeps every run")
        expect(check_lossless(made_up(below, held=again), sink),
               "the check passes a repeat on the build the first run measured")
        expect(not check_lossless(made_up(below, held=other), sink),
               "the check does not pass the held-out gates of another build's later run")

    # A ledger booked before the build digest's key was renamed: the stage
    # refuses it as it is, and once rewritten it books the next run on the
    # same recordings as a repeat of the build the first run measured.
    with tempfile.TemporaryDirectory() as tmp:
        ledger = Path(tmp) / "heldout" / "runs.json"
        ledger.parent.mkdir()
        ledger.write_text(json.dumps(dict(note="test", runs=[
            {"date": "day 1", OLD_KEY: "aaaa", "git": "rev1", "recordings": ["a1", "a2"]}]),
            indent=1) + "\n")
        before = ledger.read_text()
        try:
            held_out_entry(ledger, ["a1", "a2"], "day 2", "aaaa", "rev2")
            refused = False
        except SystemExit:
            refused = True
        expect(refused and ledger.read_text() == before,
               "a ledger under the former key is refused and left as it was")
        renamed, again = rename_ledger(ledger), rename_ledger(ledger)
        expect(renamed == 1 and again == 0 and json.loads(ledger.read_text())["note"] == "test",
               "the ledger rewrite renames each run's digest once and keeps the rest")
        repeat = held_out_entry(ledger, ["a2", "a1"], "day 2", "aaaa", "rev2")
        expect(not repeat["first_run"] and repeat["run"] == 2 and repeat["same_build_as_first"]
               and repeat["first_date"] == "day 1",
               "a rewritten ledger books the next run on its recordings as a repeat, not a first")
        runs = json.loads(ledger.read_text())["runs"]
        ledger.write_text(json.dumps(dict(runs=runs + [{"date": "day 3", OLD_KEY: "cccc"}])))
        try:
            rename_ledger(ledger)
            mixed_refused = False
        except SystemExit:
            mixed_refused = True
        expect(mixed_refused, "a ledger that books runs under both keys is refused")
        ledger.write_text(json.dumps(dict(runs=runs + [{"date": "day 3", "git": "rev3"}])))
        try:
            rename_ledger(ledger)
            bare_refused = False
        except SystemExit:
            bare_refused = True
        expect(bare_refused, "a ledger with a run that has no build digest is refused")

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
        run_of.update(tools=dict(bac="x", flac="flac 1.4.3", ffmpeg="ffmpeg", refalac="carried"),
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
        expect(old.get("Lossless") != new["Lossless"] and "bac-5" in new["Lossless"] and
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
