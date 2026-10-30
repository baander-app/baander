/* A mutation driver for the fuzz harnesses when libFuzzer is not
 * available: the harness's LLVMFuzzerTestOneInput runs over every seed
 * as it is, then for a time budget over mutations of the seeds (bit
 * flips, byte writes, arithmetic, insertions, deletions, duplications,
 * truncation, interesting integers, and splices and copies between two
 * seeds).  An input that faults, trips a sanitizer, calls fuzz_fail or
 * runs over the per-input timeout is written to the artifact directory
 * as a reproducer, and running the harness on that file alone replays
 * it.
 *
 *   harness [--seconds N] [--runs N] [--max-len N] [--seed N]
 *           [--timeout N] [--artifacts DIR] [--quiet] <seed file or directory>...
 *
 * Without --seconds and --runs the seeds are replayed once (the
 * reproducer mode), and a faulting input is written out only when
 * --artifacts is given.  A fuzzing run writes to the current directory
 * without --artifacts.  The run is deterministic for a seed and a corpus,
 * so a finding reproduces from the printed seed as well as from the
 * artifact. */
#include <dirent.h>
#include <fcntl.h>
#include <signal.h>
#include <sys/stat.h>
#include <unistd.h>

#include <algorithm>
#include <chrono>
#include <cstring>
#include <string>
#include <vector>

#include "fuzz_driver.h"

/* ASan and UBSan call this before they exit; without a sanitizer the
 * symbol is absent and the signal handlers alone save the input. */
extern "C" __attribute__((weak)) void __sanitizer_set_death_callback(void (*callback)(void));

/* The sanitizers' defaults for the harnesses: a report aborts (so the
 * SIGABRT handler saves the input even where the death callback is not
 * called, as for UBSan) and every undefined-behaviour report is fatal.
 * The environment's ASAN_OPTIONS and UBSAN_OPTIONS still override. */
extern "C" __attribute__((visibility("default"))) const char* __asan_default_options() {
  return "abort_on_error=1";
}
extern "C" __attribute__((visibility("default"))) const char* __ubsan_default_options() {
  return "halt_on_error=1:abort_on_error=1:print_stacktrace=1";
}

namespace {

/* The input under test, for the crash handlers (async-signal-safe
 * writes only). */
const uint8_t* g_current = nullptr;
size_t g_current_size = 0;
unsigned long long g_current_run = 0;
char g_artifact_dir[1024] = ".";
char g_harness[256] = "fuzz";
volatile sig_atomic_t g_saving = 0;
/* Whether a faulting input is written out: in a fuzzing run, or when
 * --artifacts names a directory; a replay of files leaves no copies. */
bool g_save = false;
const char* g_kind = "crash";

void write_all(int fd, const char* s, size_t n) {
  while (n > 0) {
    const ssize_t w = write(fd, s, n);
    if (w <= 0) {
      return;
    }
    s += w;
    n -= (size_t)w;
  }
}

/* Writes the current input as <artifacts>/<kind>-<harness>-<run>.bin,
 * with nothing that is not async-signal-safe. */
void save_current_input() {
  if (!g_save || g_saving || !g_current) {
    return;
  }
  g_saving = 1;
  char path[1400];
  char num[32];
  int n = 0;
  unsigned long long v = g_current_run;
  do {
    num[n++] = (char)('0' + v % 10);
    v /= 10;
  } while (v);
  size_t p = 0;
  auto append = [&](const char* s) {
    while (*s && p + 1 < sizeof(path)) {
      path[p++] = *s++;
    }
  };
  append(g_artifact_dir);
  append("/");
  append(g_kind);
  append("-");
  append(g_harness);
  append("-");
  while (n > 0 && p + 1 < sizeof(path)) {
    path[p++] = num[--n];
  }
  append(".bin");
  path[p] = 0;
  const int fd = open(path, O_WRONLY | O_CREAT | O_TRUNC, 0644);
  if (fd >= 0) {
    write_all(fd, (const char*)g_current, g_current_size);
    close(fd);
  }
  write_all(2, "fuzz: input saved to ", 21);
  write_all(2, path, p);
  write_all(2, "\n", 1);
}

void on_fatal_signal(int sig) {
  save_current_input();
  signal(sig, SIG_DFL);
  raise(sig);
}

void on_timeout(int sig) {
  g_kind = "timeout";
  write_all(2, "fuzz: per-input timeout\n", 24);
  save_current_input();
  signal(SIGABRT, SIG_DFL);
  (void)sig;
  abort();
}

/* xorshift64*: small, fast and reproducible across platforms. */
struct Rng {
  uint64_t s;
  explicit Rng(uint64_t seed) : s(seed ? seed : 0x9E3779B97F4A7C15ull) {}
  uint64_t next() {
    s ^= s >> 12;
    s ^= s << 25;
    s ^= s >> 27;
    return s * 0x2545F4914F6CDD1Dull;
  }
  uint32_t below(uint32_t n) { return n ? (uint32_t)(next() % n) : 0; }
  bool coin() { return next() & 1; }
};

const int32_t kInteresting[] = {0,     1,     2,     3,     4,     7,      8,      15,    16,    31,
                                32,    63,    64,    100,   127,   128,    255,    256,   511,   512,
                                1023,  1024,  1025,  2047,  2048,  4095,   4096,   8191,  8192,  16383,
                                16384, 32767, 32768, 65535, 65536, -1,     -2,     -128,  -256,  -32768,
                                96000, 192000, 0xFFFFFF, (int32_t)0x7FFFFFFF, (int32_t)0x80000000};

/* One random mutation of `v`, bounded by `max_len`; `other` is a second
 * corpus entry for the splice and copy mutations. */
void mutate(std::vector<uint8_t>& v, const std::vector<uint8_t>& other, size_t max_len, Rng& rng) {
  const uint32_t kind = rng.below(14);
  if (v.empty() && kind < 8) {
    v.push_back((uint8_t)rng.next());
    return;
  }
  switch (kind) {
    case 0: { /* flip one bit */
      const size_t i = rng.below((uint32_t)v.size());
      v[i] ^= (uint8_t)(1u << rng.below(8));
      break;
    }
    case 1: { /* a random byte */
      v[rng.below((uint32_t)v.size())] = (uint8_t)rng.next();
      break;
    }
    case 2: { /* a small arithmetic change */
      const size_t i = rng.below((uint32_t)v.size());
      v[i] = (uint8_t)(v[i] + (int)rng.below(17) - 8);
      break;
    }
    case 3: { /* an interesting integer of 1, 2 or 4 bytes, either byte order */
      const int32_t x = kInteresting[rng.below(sizeof(kInteresting) / sizeof(kInteresting[0]))];
      const size_t width = (size_t)1 << rng.below(3);
      if (v.size() < width) {
        break;
      }
      const size_t i = rng.below((uint32_t)(v.size() - width + 1));
      const bool big = rng.coin();
      for (size_t k = 0; k < width; k++) {
        const size_t shift = big ? (width - 1 - k) * 8 : k * 8;
        v[i + k] = (uint8_t)((uint32_t)x >> shift);
      }
      break;
    }
    case 4: { /* delete a range */
      const size_t i = rng.below((uint32_t)v.size());
      const size_t n = 1 + rng.below((uint32_t)std::min<size_t>(v.size() - i, 64));
      v.erase(v.begin() + (long)i, v.begin() + (long)(i + n));
      break;
    }
    case 5: { /* insert random bytes */
      const size_t i = rng.below((uint32_t)v.size() + 1);
      const size_t n = 1 + rng.below(16);
      std::vector<uint8_t> ins(n);
      for (uint8_t& b : ins) {
        b = (uint8_t)rng.next();
      }
      v.insert(v.begin() + (long)i, ins.begin(), ins.end());
      break;
    }
    case 6: { /* duplicate a range in place */
      const size_t i = rng.below((uint32_t)v.size());
      const size_t n = 1 + rng.below((uint32_t)std::min<size_t>(v.size() - i, 256));
      const std::vector<uint8_t> dup(v.begin() + (long)i, v.begin() + (long)(i + n));
      v.insert(v.begin() + (long)(i + n), dup.begin(), dup.end());
      break;
    }
    case 7: { /* truncate */
      v.resize(rng.below((uint32_t)v.size()));
      break;
    }
    case 8: { /* splice: our head, the other's tail */
      if (other.empty()) {
        break;
      }
      const size_t i = rng.below((uint32_t)v.size() + 1);
      const size_t j = rng.below((uint32_t)other.size());
      v.resize(i);
      v.insert(v.end(), other.begin() + (long)j, other.end());
      break;
    }
    case 9: { /* copy a chunk of the other over ours */
      if (other.empty() || v.empty()) {
        break;
      }
      const size_t j = rng.below((uint32_t)other.size());
      const size_t n = 1 + rng.below((uint32_t)std::min<size_t>(other.size() - j, 64));
      const size_t i = rng.below((uint32_t)v.size());
      for (size_t k = 0; k < n && i + k < v.size(); k++) {
        v[i + k] = other[j + k];
      }
      break;
    }
    case 10: { /* set a run of bytes to one value */
      const size_t i = rng.below((uint32_t)v.size());
      const size_t n = 1 + rng.below((uint32_t)std::min<size_t>(v.size() - i, 32));
      const uint8_t b = rng.coin() ? (uint8_t)rng.next() : (rng.coin() ? 0x00 : 0xFF);
      std::fill(v.begin() + (long)i, v.begin() + (long)(i + n), b);
      break;
    }
    case 11: { /* flip several bits around one spot (a damaged byte burst) */
      const size_t i = rng.below((uint32_t)v.size());
      for (int k = 0; k < 8 && i + (size_t)k < v.size(); k++) {
        if (rng.coin()) {
          v[i + (size_t)k] ^= (uint8_t)(1u << rng.below(8));
        }
      }
      break;
    }
    case 12: { /* grow with the other's bytes appended */
      if (other.empty()) {
        break;
      }
      const size_t n = 1 + rng.below((uint32_t)std::min<size_t>(other.size(), 512));
      v.insert(v.end(), other.begin(), other.begin() + (long)n);
      break;
    }
    default: { /* swap two bytes */
      if (v.size() >= 2) {
        std::swap(v[rng.below((uint32_t)v.size())], v[rng.below((uint32_t)v.size())]);
      }
      break;
    }
  }
  if (v.size() > max_len) {
    v.resize(max_len);
  }
}

bool read_file(const std::string& path, std::vector<uint8_t>& out) {
  FILE* f = fopen(path.c_str(), "rb");
  if (!f) {
    return false;
  }
  out.clear();
  uint8_t buf[65536];
  size_t n;
  while ((n = fread(buf, 1, sizeof(buf), f)) > 0) {
    out.insert(out.end(), buf, buf + n);
  }
  fclose(f);
  return true;
}

void collect(const std::string& path, std::vector<std::vector<uint8_t>>& corpus,
             std::vector<std::string>& names) {
  struct stat st;
  if (stat(path.c_str(), &st) != 0) {
    fprintf(stderr, "fuzz: cannot read %s\n", path.c_str());
    return;
  }
  if (S_ISDIR(st.st_mode)) {
    DIR* d = opendir(path.c_str());
    if (!d) {
      return;
    }
    std::vector<std::string> entries;
    while (dirent* e = readdir(d)) {
      if (e->d_name[0] != '.') {
        entries.push_back(path + "/" + e->d_name);
      }
    }
    closedir(d);
    std::sort(entries.begin(), entries.end());
    for (const std::string& e : entries) {
      collect(e, corpus, names);
    }
    return;
  }
  std::vector<uint8_t> data;
  if (read_file(path, data)) {
    corpus.push_back(std::move(data));
    names.push_back(path);
  }
}

double now_seconds() {
  return std::chrono::duration<double>(std::chrono::steady_clock::now().time_since_epoch()).count();
}

void run_one(const std::vector<uint8_t>& input, unsigned timeout_seconds) {
  g_current = input.data();
  g_current_size = input.size();
  g_current_run++;
  if (timeout_seconds) {
    alarm(timeout_seconds);
  }
  LLVMFuzzerTestOneInput(input.data(), input.size());
  if (timeout_seconds) {
    alarm(0);
  }
}

}  // namespace

int main(int argc, char** argv) {
  double seconds = -1.0;
  long long runs = -1;
  size_t max_len = 65536;
  uint64_t seed = 1;
  unsigned timeout_seconds = 20;
  bool quiet = false;
  std::vector<std::string> paths;
  for (int i = 1; i < argc; i++) {
    const std::string a = argv[i];
    const bool more = i + 1 < argc;
    if (a == "--seconds" && more) {
      seconds = atof(argv[++i]);
    } else if (a == "--runs" && more) {
      runs = atoll(argv[++i]);
    } else if (a == "--max-len" && more) {
      max_len = (size_t)atol(argv[++i]);
    } else if (a == "--seed" && more) {
      seed = (uint64_t)atoll(argv[++i]);
    } else if (a == "--timeout" && more) {
      timeout_seconds = (unsigned)atoi(argv[++i]);
    } else if (a == "--artifacts" && more) {
      snprintf(g_artifact_dir, sizeof(g_artifact_dir), "%s", argv[++i]);
      g_save = true;
    } else if (a == "--quiet") {
      quiet = true;
    } else if (a.size() > 1 && a[0] == '-') {
      fprintf(stderr,
              "usage: %s [--seconds N] [--runs N] [--max-len N] [--seed N] [--timeout N]\n"
              "          [--artifacts DIR] [--quiet] <seed file or directory>...\n",
              argv[0]);
      return 2;
    } else {
      paths.push_back(a);
    }
  }
  const char* slash = strrchr(argv[0], '/');
  snprintf(g_harness, sizeof(g_harness), "%s", slash ? slash + 1 : argv[0]);

  std::vector<std::vector<uint8_t>> corpus;
  std::vector<std::string> names;
  for (const std::string& p : paths) {
    collect(p, corpus, names);
  }
  if (corpus.empty()) {
    corpus.emplace_back(); /* the empty input is a seed of its own */
    names.push_back("(empty)");
  }

  if (__sanitizer_set_death_callback) {
    __sanitizer_set_death_callback(save_current_input);
  }
  if (seconds >= 0 || runs >= 0) {
    g_save = true;
  }
  for (int sig : {SIGSEGV, SIGBUS, SIGILL, SIGFPE, SIGABRT}) {
    signal(sig, on_fatal_signal);
  }
  signal(SIGALRM, on_timeout);

  /* Every seed as it is: the reproducer mode, and the corpus check of a
   * fuzzing run. */
  for (size_t i = 0; i < corpus.size(); i++) {
    if (!quiet && seconds < 0 && runs < 0) {
      fprintf(stderr, "fuzz: %s (%zu bytes)\n", names[i].c_str(), corpus[i].size());
    }
    run_one(corpus[i], timeout_seconds);
  }
  if (seconds < 0 && runs < 0) {
    if (!quiet) {
      fprintf(stderr, "fuzz: %zu input(s) replayed without a finding\n", corpus.size());
    }
    return 0;
  }

  Rng rng(seed);
  const double t0 = now_seconds();
  const double deadline = seconds < 0 ? 1e300 : t0 + seconds;
  const long long budget = runs < 0 ? (1ll << 62) : runs;
  long long done = 0;
  size_t longest = 0;
  std::vector<uint8_t> input;
  while (done < budget) {
    if ((done & 63) == 0 && now_seconds() >= deadline) {
      break;
    }
    input = corpus[rng.below((uint32_t)corpus.size())];
    const std::vector<uint8_t>& other = corpus[rng.below((uint32_t)corpus.size())];
    const uint32_t steps = 1 + rng.below(rng.coin() ? 2 : 12);
    for (uint32_t s = 0; s < steps; s++) {
      mutate(input, other, max_len, rng);
    }
    longest = std::max(longest, input.size());
    run_one(input, timeout_seconds);
    done++;
  }
  const double elapsed = now_seconds() - t0;
  if (!quiet) {
    fprintf(stderr,
            "fuzz: %s: %lld mutated runs over %zu seeds in %.1f s (%.0f/s, longest %zu bytes, "
            "seed %llu), no finding\n",
            g_harness, done, corpus.size(), elapsed, elapsed > 0 ? (double)done / elapsed : 0.0,
            longest, (unsigned long long)seed);
  }
  return 0;
}
