"""What the stages of the codec comparison harness share: where it works,
the tools it runs, the corpus items, and running a command."""
import os
import shlex
import shutil
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
WORK = Path(os.environ.get("BENCH_WORK", HERE / "work"))

# Every tool the harness starts runs in the C locale: flac reads the times
# given to --skip and --until with the locale's decimal separator, and
# refuses 5:00.00 where that separator is a comma.  The harness sets the
# locale in its own environment before it starts any tool, so every child
# inherits it and no timed command needs a wrapper.
os.environ["LC_ALL"] = "C"

TOOLS = {
    "bac": os.environ.get("BAC", str(HERE.parent / "cmake-build" / "bac")),
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


def have(tool):
    path = TOOLS[tool]
    if not path:
        return False
    if tool in ("qaac", "refalac"):
        return Path(path).is_file() and shutil.which(TOOLS["wine"]) is not None
    return shutil.which(path) is not None or Path(path).is_file()


def wine(tool):
    return [TOOLS["wine"], TOOLS[tool]]


def run(cmd, timed=None, **kw):
    """Run cmd; with timed=path, record wall, user, sys seconds and max RSS."""
    if timed:
        cmd = ["/usr/bin/time", "-f", "%e %U %S %M", "-o", timed] + cmd
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kw)
    if p.returncode != 0:
        raise RuntimeError(f"{shlex.join(cmd)} failed ({p.returncode}): "
                           f"{p.stderr.decode(errors='replace')[-600:]}")
    return p
