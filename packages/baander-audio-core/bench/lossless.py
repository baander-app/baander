"""The lossless stage of the codec comparison harness (`bench.py lossless`
and `bench.py check`): bAAC's lossless mode against FLAC and ALAC on the
same corpus and on held-out recordings, with the gates of the lossless
mode.  The comment below is the full statement of the method."""
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
import time
import wave
from pathlib import Path

from common import HERE, MUSIC, TOOLS, WORK, have, run, wine

# The stage measures bAAC's lossless mode (the `baac flac`
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
# been run on before the recorded run: a 96 kHz 24-bit stereo file,
# twenty seconds of a 24-bit 5.1 film mix and a minute of a 16-bit
# stereo file (fetch-heldout.sh).  The stage touches them only in a
# complete run with --check that starts on a quiet host, writes a ledger
# entry before it reads the first of them, and records in its result
# whether that run was the first on these recordings.  The ledger names
# each run's recordings by their SHA-256, so a run on a new set is that
# set's first however many runs earlier sets had, and a set that shares
# a file with an earlier one is not new.  A later run with another build
# of baac is not a gate result for these recordings.  Each set's total
# frame bytes gate at level 5, with an encode gate and two decode gates.
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
TESTBENCH = Path(os.environ.get("BAC_FLAC_TESTBENCH", HERE / "work" / "flac-test-files"))
HELDOUT_DIR = Path(os.environ.get("BAC_HELDOUT", HERE / "work" / "heldout"))
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
# The held-out set.  Each span was fixed from the file's duration alone,
# before anything decoded it: the 62-second piano recording whole,
# twenty seconds of the film mix from 5:00, as for the film master of the
# set before, and the minute after the first minute of the 4:21 track.
HELD_OUT = [
    ("hires", "96 kHz, 24-bit stereo", dict(sample_rate=96000, channels=2, bits=24),
     [("avon-chang-cello-suite-6.flac", None, None)]),
    ("surround", "5.1, 48 kHz, 24-bit", dict(sample_rate=48000, channels=6, bits=24),
     [("tearsofsteel-surround.flac", "5:00.00", "5:20.00")]),
    ("stereo16", "44.1 kHz, 16-bit stereo", dict(sample_rate=44100, channels=2, bits=16),
     [("cloudkicker-let-yourself-be-huge.flac", "1:00.00", "2:00.00")]),
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
    cache = Path(TOOLS["bac"]).parent / "CMakeCache.txt"
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
    return [TOOLS["bac"], "flac", "enc", "--level", str(level)] + raw + [str(src), str(out)]


def enc_flac(level, src, out, fmt=None):
    return [TOOLS["flac"], "-s", "-f", f"-{level}", "-o", str(out)] + \
        (raw_args(fmt) if fmt else []) + [str(src)]


def dec_baac(stream, out, md5=True, raw=False):
    return [TOOLS["bac"], "flac", "dec"] + ([] if md5 else ["--no-md5"]) + \
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


def held_out_entry(ledger_path, recordings, date, baac_sha256, git):
    """Books a run on the held-out recordings in the ledger, before the
    run reads the first of them, and returns what the result records:
    whether it is the first run on these recordings, and whether it
    measures the build of baac that first run measured.  `recordings` are
    the SHA-256 digests of the files the run reads.  Earlier runs count
    when they read any of these files; a run booked without its digests
    read an earlier set.  An interrupted run stays booked: the recordings
    were read."""
    runs = json.loads(ledger_path.read_text())["runs"] if ledger_path.exists() else []
    recordings = sorted(recordings)
    seen = [r for r in runs if set(r.get("recordings", ())) & set(recordings)]
    record = dict(touched=True, run=len(seen) + 1, first_run=not seen,
                  same_build_as_first=not seen or seen[0]["baac_sha256"] == baac_sha256,
                  first_date=seen[0]["date"] if seen else date, baac_sha256=baac_sha256,
                  recordings=recordings)
    runs.append(dict(date=date, baac_sha256=baac_sha256, git=git, recordings=recordings))
    ledger_path.parent.mkdir(parents=True, exist_ok=True)
    ledger_path.write_text(json.dumps(dict(
        note="Runs of the lossless stage on the held-out recordings, each with the SHA-256 of "
             "the files it read; a run without them read an earlier set. Deleting this file "
             "does not make the recordings unseen.", runs=runs), indent=1) + "\n")
    return record


def held_out_files():
    """The paths of the held-out recordings, each checked to exist and to
    have its set's format, read from STREAMINFO alone, so that a run that
    could not read them stops before it is booked."""
    paths = []
    for _, _, expected, sources in HELD_OUT:
        for name, _, _ in sources:
            path = HELDOUT_DIR / name
            if not path.exists():
                raise SystemExit(f"the held-out recording \"{path}\" is missing: run "
                                 "./fetch-heldout.sh")
            check_held_out_format(name, flac_properties(path)[0], expected)
            paths.append(path)
    return paths


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




def enc_alac(src, out):
    return ffmpeg_cmd("-i", str(src), "-c:a", "alac", str(out))


def dec_alac(stream, out, raw=False):
    return ffmpeg_cmd("-i", str(stream), "-c:a", "pcm_s16le", "-f", "s16le" if raw else "wav",
                      str(out))


# ---------------------------------------------------------------- the stage
#
# stage_lossless runs the steps below in order.  They share one
# LosslessRun: the work directory, the core and the repeats, the corpus,
# and the gates and figures judged so far.

class LosslessRun:
    def __init__(self, args, flac_version):
        self.args = args
        self.flac_version = flac_version
        self.d = WORK / "lossless"
        self.d.mkdir(parents=True, exist_ok=True)
        # The rest of the host over the minute before the run, then from
        # here to the end of the run.
        print(f"lossless: measuring the host for {QUIET_WINDOW} s", file=sys.stderr)
        before = cpu_sample()
        time.sleep(QUIET_WINDOW)
        self.start = cpu_sample()
        self.other_start = other_cpu(before, self.start)
        self.core = args.core if args.core is not None else pick_core()
        self.repeats = max(args.repeat, 5)
        self.only = re.compile(args.only or ".")
        # A quiet-host limit above the rule's is a measurement for
        # information, like the other limits: it cannot make a gate result.
        self.limited = bool(args.only or args.gates_only or args.any_flac or args.reps
                            or args.quiet_cpu > QUIET_CPU)
        self.load_start = os.getloadavg()
        self.started = datetime.datetime.now().astimezone().isoformat(timespec="seconds")
        self.host = host_info(self.core)
        self.gates, self.figures = [], []

    def gate(self, gid, group, text, result, judged_on=None):
        g = dict(id=gid, group=group, description=text, **result)
        if judged_on:
            g["judged_on"] = judged_on
        self.gates.append(g)
        return g


def stage_lossless(args):
    ctx = begin(args)
    prepare_inputs(ctx)
    rows = flac_rows(ctx)
    alac = measure_alac_rows(ctx)
    ctx.by = {r["codec"]: r for r in rows + alac}
    for r in rows:
        print(f"  {r['codec']}: {r['kbps']:.2f} kbit/s, {r['frame_bytes']} frame bytes, "
              f"bit-exact {r['bit_exact']}", file=sys.stderr)
    row_speeds(ctx, rows)
    flac_size_gates(ctx)
    encode_gates(ctx)
    decode_gates(ctx)
    alac_gates(ctx, alac)
    added, corpus_totals = corpus_additions(ctx)
    held, held_run = held_out(ctx)
    finish(ctx, rows + alac, added, corpus_totals, held, held_run)


def begin(args):
    """Checks the tools and starts a run on the idlest core."""
    for tool in ("bac", "flac", "ffmpeg"):
        if not have(tool):
            raise SystemExit(f"the lossless stage needs {tool} ({TOOLS[tool]})")
    flac_version = tool_version([TOOLS["flac"], "--version"])
    if flac_version != "flac 1.4.3" and not args.any_flac:
        raise SystemExit(f"FLAC is \"{flac_version}\", not the pinned flac 1.4.3: run "
                         "./setup-refs.sh and source work/ext/env.sh (or pass --any-flac for a "
                         "run that is not a gate result)")
    ctx = LosslessRun(args, flac_version)
    print(f"lossless: core {ctx.core}, {ctx.repeats} repeats, the rest of the host using "
          f"{ctx.other_start:.2f} CPUs, load {ctx.load_start[0]:.2f}", file=sys.stderr)
    return ctx


def prepare_inputs(ctx):
    """The corpus: the ten items back to back, once and repeated for
    timing, and each item alone as raw PCM and as WAV."""
    d = ctx.d
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
    ctx.fmt, ctx.items = fmt, items
    ctx.source = (d / "corpus.raw").read_bytes()
    ctx.seconds = len(ctx.source) / (48000 * 4)
    ctx.reps = long_input(d, "corpus", d / "corpus.raw", fmt, ctx.core, ctx.args.reps)
    ctx.long_wav, ctx.long_raw = d / "corpus.long.wav", d / "corpus.long.raw"
    print(f"corpus: {ctx.seconds:.1f} s, repeated {ctx.reps} times for timing", file=sys.stderr)


def flac_row(ctx, codec, encoder, level, enc_cmd):
    """One FLAC encoder at one level, one pass over the corpus: its size,
    and whether every decoder gives the source back."""
    d, core = ctx.d, ctx.core
    o = d / f"{codec}.flac"
    cpu_run(enc_cmd(d / "corpus.wav", o), core)
    exact = True
    for dec in (lambda out: dec_baac(o, out, raw=True), lambda out: dec_flac(o, out, raw=True),
                lambda out: dec_ffmpeg(o, out, raw=True)):
        exact = exact and same_bytes(decoded(dec, core), ctx.source)
    size = os.path.getsize(o)
    return dict(codec=codec, family="flac", encoder=encoder, level=level, seconds=ctx.seconds,
                bytes=size, kbps=size * 8 / ctx.seconds / 1000, frame_bytes=flac_frame_bytes(o),
                bit_exact=bool(exact))


def flac_rows(ctx):
    """bAAC and flac at every level and FFmpeg's FLAC encoder at 5 and 8."""
    rows = []
    for level in LOSSLESS_LEVELS:
        rows.append(flac_row(ctx, f"baac-{level}", "baac", level,
                             lambda src, o, level=level: enc_baac(level, src, o)))
    for level in LOSSLESS_LEVELS:
        rows.append(flac_row(ctx, f"flac-{level}", "flac", level,
                             lambda src, o, level=level: enc_flac(level, src, o)))
    for level in (5, 8):
        rows.append(flac_row(ctx, f"flac-ffmpeg-{level}", "ffmpeg", level,
                             lambda src, o, level=level: ffmpeg_cmd(
                                 "-i", str(src), "-c:a", "flac", "-compression_level", str(level),
                                 str(o))))
    return rows


def measure_alac_rows(ctx):
    """FFmpeg's ALAC encoder and, when it runs here, refalac: size and
    exactness.  Without refalac its row is the carried one."""
    d, core, seconds, source = ctx.d, ctx.core, ctx.seconds, ctx.source
    cpu_run(enc_alac(d / "corpus.wav", d / "alac-ffmpeg.m4a"), core)
    size = os.path.getsize(d / "alac-ffmpeg.m4a")
    measured = [dict(codec="alac-ffmpeg", family="alac", encoder="ffmpeg", seconds=seconds,
                     bytes=size, kbps=size * 8 / seconds / 1000,
                     bit_exact=bool(same_bytes(decoded(
                         lambda out: dec_alac(d / "alac-ffmpeg.m4a", out, raw=True), core),
                         source)))]
    if have("refalac"):
        o = d / "alac-apple-ref.m4a"
        run(wine("refalac") + ["--silent", "-o", str(o), str(d / "corpus.wav")])
        size = os.path.getsize(o)
        measured.append(dict(codec="alac-apple-ref", family="alac", encoder="refalac",
                             seconds=seconds, bytes=size, kbps=size * 8 / seconds / 1000,
                             bit_exact=bool(same_bytes(decoded(
                                 lambda out: dec_alac(o, out, raw=True), core), source))))
    return alac_rows(have("refalac"), measured)


def row_speeds(ctx, rows):
    """The speed of every FLAC row on the long input from WAV, unless the
    run judges the gates only; then the neutral streams of the decode
    gates."""
    d, core, reps, seconds, long_wav = ctx.d, ctx.core, ctx.reps, ctx.seconds, ctx.long_wav
    row_repeats = 3
    if not ctx.args.gates_only:
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


def flac_size_gates(ctx):
    for level in (5, 8):
        ctx.gate(f"size-{level}", "flac", f"bAAC level {level} frame bytes against flac -{level}",
                 dict(kind="size", **size_verdict(ctx.by[f"baac-{level}"]["frame_bytes"],
                                                  ctx.by[f"flac-{level}"]["frame_bytes"])))


def encode_gates(ctx):
    """Encode against flac at levels 5 and 8, judged on the long run from
    raw PCM, with the three other figures recorded beside it."""
    d, fmt, items, reps = ctx.d, ctx.fmt, ctx.items, ctx.reps
    long_raw, long_wav = ctx.long_raw, ctx.long_wav
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
        if not ctx.only.search(gid):
            continue
        for variant, mk in variants.items():
            r = measure_gate(f"{gid} {variant}", mk(enc_baac, level), mk(enc_flac, level),
                             ctx.repeats, ctx.core)
            ctx.figures.append(dict(measure=gid, variant=variant, judged=variant == "long-raw",
                                    **r))
            if variant == "long-raw":
                ctx.gate(gid, "flac", f"encode at level {level} against flac -{level}",
                         dict(kind="speed", **r), "one process over the repeated corpus, raw input")


def decode_gates(ctx):
    """Decode of flac's own streams against flac and FFmpeg, judged on the
    long run, with one process per item recorded beside it."""
    d, core, items, reps = ctx.d, ctx.core, ctx.items, ctx.reps
    for level in (5, 8):
        stream = d / f"flac-{level}.long.flac"
        for name, n in ((f"item.{n}", n) for n in items):
            if not (d / f"{name}.flac-{level}.flac").exists():
                cpu_run(enc_flac(level, d / f"{name}.wav", d / f"{name}.flac-{level}.flac"), core)
        per_file = [d / f"item.{n}.flac-{level}.flac" for _ in range(reps) for n in items]
        for ref, ours_md5, their in (("flac", True, dec_flac), ("ffmpeg", False, dec_ffmpeg)):
            gid = f"dec-{level}-{ref}"
            if not ctx.only.search(gid):
                continue
            checks = "MD5 verified by both" if ref == "flac" else \
                "frame CRCs checked by both, no MD5"
            r = measure_gate(f"{gid} long", [dec_baac(stream, "/dev/null", md5=ours_md5)],
                             [their(stream, "/dev/null")], ctx.repeats, core)
            ctx.figures.append(dict(measure=gid, variant="long", judged=True, **r))
            ctx.gate(gid, "decode", f"decode of flac -{level} streams against {ref} ({checks})",
                     dict(kind="speed", **r), "one process over the repeated corpus")
            r = measure_gate(f"{gid} per-file",
                             [dec_baac(s, "/dev/null", md5=ours_md5) for s in per_file],
                             [their(s, "/dev/null") for s in per_file], ctx.repeats, core)
            ctx.figures.append(dict(measure=gid, variant="per-file", judged=False, **r))


def alac_gates(ctx, alac):
    """bAAC at the default level against the ALAC encoders: size, and
    against FFmpeg's encoder and decoder, speed."""
    d, core, seconds, reps, long_wav = ctx.d, ctx.core, ctx.seconds, ctx.reps, ctx.long_wav
    ours5 = ctx.by["baac-5"]
    for r in alac:
        ref_bytes = r.get("bytes") or round(r["kbps"] * r["seconds"] * 125)
        ours_bytes = ours5["bytes"] if r["seconds"] == seconds else \
            round(ours5["kbps"] * r["seconds"] * 125)
        note = f" (row carried from {r['carried']}, size only)" if r.get("carried") else ""
        ctx.gate(f"alac-size-{'refalac' if r['codec'] == 'alac-apple-ref' else 'ffmpeg'}", "alac",
                 f"bAAC level 5 stream against {r['codec']}{note}",
                 dict(kind="size", **size_verdict(ours_bytes, ref_bytes)))
    if ctx.only.search("alac-enc"):
        r = measure_gate("alac-enc", [enc_baac(5, long_wav, d / "timing.flac")],
                         [enc_alac(long_wav, d / "timing.m4a")], ctx.repeats, core)
        ctx.figures.append(dict(measure="alac-enc", variant="long-wav", judged=True, **r))
        ctx.gate("alac-enc", "alac", "encode at level 5 against FFmpeg ALAC",
                 dict(kind="speed", **r),
                 "one process over the repeated corpus, WAV input, both writing a file")
        ctx.by["alac-ffmpeg"].update(enc_cpu=r["reference_median"] / reps)
    if ctx.only.search("alac-dec"):
        if not (d / "timing.m4a").exists():
            cpu_run(enc_alac(long_wav, d / "timing.m4a"), core)
        r = measure_gate("alac-dec", [dec_baac(d / "baac-5.long.flac", "/dev/null")],
                         [dec_alac(d / "timing.m4a", "/dev/null")], ctx.repeats, core)
        ctx.figures.append(dict(measure="alac-dec", variant="long", judged=True, **r))
        ctx.gate("alac-dec", "alac",
                 "decode of its own level 5 stream against FFmpeg decoding ALAC",
                 dict(kind="speed", **r), "one process over the repeated corpus")
        ctx.by["alac-ffmpeg"].update(dec_cpu=r["reference_median"] / reps)
    for f in ("timing.flac", "timing.m4a"):
        (d / f).unlink(missing_ok=True)


def measure_set(ctx, prefix, group, what, directory, set_id, label, expected, sources, levels):
    """One set of recordings beyond the ten items: sizes file by file at
    `levels`, exactness and the level 5 gates on the set as one input.
    Returns the per-file records and the set's frame bytes by level and
    encoder."""
    d, core = ctx.d, ctx.core
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
    ctx.gate(f"{prefix}-{set_id}-size", group,
             f"{what} {label}: level 5 frame bytes against flac -5",
             dict(kind="size", **size_verdict(totals[5]["baac"], totals[5]["flac"])))
    set_reps = long_input(d, f"{prefix}.{set_id}", set_raw, expected, core)
    lraw = d / f"{prefix}.{set_id}.long.raw"
    stream = d / f"{prefix}.{set_id}.long.flac"
    cpu_run(enc_flac(5, lraw, stream, expected), core)
    r = measure_gate(f"{prefix}-{set_id}-enc", [enc_baac(5, lraw, "/dev/null", expected)],
                     [enc_flac(5, lraw, "/dev/null", expected)], ctx.repeats, core)
    ctx.gate(f"{prefix}-{set_id}-enc", group,
             f"{what} {label}: encode at level 5 against flac -5",
             dict(kind="speed", **r), f"one process over the set repeated {set_reps} times, raw "
             "input")
    r = measure_gate(f"{prefix}-{set_id}-dec-flac", [dec_baac(stream, "/dev/null")],
                     [dec_flac(stream, "/dev/null")], ctx.repeats, core)
    ctx.gate(f"{prefix}-{set_id}-dec-flac", group,
             f"{what} {label}: decode of the flac -5 stream against flac (MD5 verified by both)",
             dict(kind="speed", **r), f"one process over the set repeated {set_reps} times")
    r = measure_gate(f"{prefix}-{set_id}-dec-ffmpeg", [dec_baac(stream, "/dev/null", md5=False)],
                     [dec_ffmpeg(stream, "/dev/null", expected["bits"])], ctx.repeats, core)
    ctx.gate(f"{prefix}-{set_id}-dec-ffmpeg", group,
             f"{what} {label}: decode of the flac -5 stream against FFmpeg (frame CRCs checked "
             "by both, no MD5)", dict(kind="speed", **r),
             f"one process over the set repeated {set_reps} times")
    for f in (lraw, stream, d / f"{prefix}.{set_id}.long.wav", set_raw,
              d / f"{prefix}.one.raw", d / f"{prefix}.baac.flac", d / f"{prefix}.flac.flac"):
        f.unlink(missing_ok=True)
    return records, totals


def corpus_additions(ctx):
    """The corpus additions, and the totals of the enlarged corpus: the
    ten items back to back and these recordings."""
    added = []
    corpus_totals = dict(original={str(level): dict(baac=ctx.by[f"baac-{level}"]["frame_bytes"],
                                                    flac=ctx.by[f"flac-{level}"]["frame_bytes"])
                                   for level in (5, 8)})
    enlarged = {level: dict(corpus_totals["original"][str(level)]) for level in (5, 8)}
    measured_all = True
    for set_id, label, expected, sources in CORPUS_ADDITIONS:
        if not ctx.only.search(f"added-{set_id}"):
            measured_all = False
            continue
        if not (TESTBENCH / "subset").is_dir():
            raise SystemExit(f"the FLAC decoder testbench is missing at {TESTBENCH}: run "
                             "../tests/flac-testbench.sh")
        records, totals = measure_set(ctx, "added", "corpus", "corpus addition",
                                      TESTBENCH / "subset", set_id, label, expected, sources,
                                      (5, 8))
        added += records
        for level in (5, 8):
            for side in ("baac", "flac"):
                enlarged[level][side] += totals[level][side]
    if measured_all:
        corpus_totals["enlarged"] = {str(level): enlarged[level] for level in (5, 8)}
        for level in (5, 8):
            ctx.gate(f"size-{level}-enlarged", "corpus",
                     f"bAAC level {level} frame bytes against flac -{level} on the enlarged corpus",
                     dict(kind="size", **size_verdict(enlarged[level]["baac"],
                                                      enlarged[level]["flac"])))
    return added, corpus_totals


def held_out(ctx):
    """The held-out recordings.  Nothing before this step has read them,
    and nothing reads them unless this is a run that can count: complete,
    asked to judge its gates, and begun on a quiet host."""
    args = ctx.args
    held = []
    if ctx.limited or not args.check:
        held_run = dict(touched=False, reason="the run was limited" if ctx.limited else
                        "the stage was not run with --check")
    elif ctx.other_start > args.quiet_cpu:
        held_run = dict(touched=False, reason=f"the host was not quiet at the start (the rest "
                                              f"of it used {ctx.other_start:.2f} CPUs)")
    else:
        recordings = [file_sha256(path) for path in held_out_files()]
        held_run = held_out_entry(HELDOUT_DIR / "runs.json", recordings, ctx.started,
                                  file_sha256(TOOLS["bac"]), git_state())
        for set_id, label, expected, sources in HELD_OUT:
            records, _ = measure_set(ctx, "held", "held-out", "held-out", HELDOUT_DIR, set_id,
                                     label, expected, sources, (5,))
            held += records
    if not held_run["touched"]:
        print(f"the held-out recordings were not read: {held_run['reason']}", file=sys.stderr)
    return held, held_run


def finish(ctx, rows, added, corpus_totals, held, held_run):
    """Writes the result of the run, and judges its gates when asked."""
    args = ctx.args
    other_run = other_cpu(ctx.start, cpu_sample())
    load_end = os.getloadavg()
    host = dict(ctx.host, other_cpu_start=round(ctx.other_start, 3),
                other_cpu_run=round(other_run, 3), quiet_cpu=args.quiet_cpu,
                load_start=[round(x, 2) for x in ctx.load_start],
                load_end=[round(x, 2) for x in load_end])
    host["quiet"] = quiet_verdict(host)[0]
    result = dict(
        schema=2, date=ctx.started, complete=not ctx.limited, host=host,
        tools=dict(baac=git_state(), flac=ctx.flac_version,
                   ffmpeg=tool_version([TOOLS["ffmpeg"], "-version"]),
                   refalac="measured" if have("refalac") else "carried from 2026-10-30"),
        method=dict(pinned=f"taskset -c {ctx.core}", repeats=ctx.repeats,
                    corpus_seconds=ctx.seconds, corpus_repetitions=ctx.reps,
                    min_cpu_seconds=MIN_CPU_SECONDS,
                    statistic="median of the paired ratios (bAAC CPU time over the reference's); "
                              "threshold 1 + (max - min) / median of the reference's times",
                    judged="long run (one process over the repeated input); encode from raw "
                           "PCM, which flac reads about 3 % faster than WAV"),
        rows=rows, gates=ctx.gates, figures=ctx.figures, corpus_totals=corpus_totals,
        corpus_additions=added, held_out=held, held_out_run=held_run)
    (WORK / "lossless.json").write_text(json.dumps(result, indent=1) + "\n")
    for f in ctx.d.glob("*.long.*"):
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
        print("not a gate result: the run was limited (--only, --gates-only, --reps, --any-flac or "
              "a raised --quiet-cpu)",
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


