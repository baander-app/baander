/* Fuzz smoke test: the seed generator writes the corpus of every
 * harness into a temporary directory, each harness replays its seeds
 * and runs a fixed number of mutations from a fixed seed, and the test
 * fails when a harness exits with a signal or a nonzero status (its
 * reproducer is then under the temporary directory, which is kept and
 * named).  The run counts fill about 20 seconds of an optimised build,
 * so the same inputs run on every machine; a slower build (a sanitizer
 * or debug build) stops at the time cap instead, after a prefix of the
 * same sequence.  The five harnesses run at the same time.
 * BAAC_FUZZ_SMOKE_SECONDS in the environment changes the cap (default
 * 60). */
#include <sys/wait.h>
#include <unistd.h>

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>

#include "test_util.h"

/* Mutated runs per harness: about 10 to 20 seconds each in a Release
 * build with the five side by side. */
#define RUNS_DECODER 2500L
#define RUNS_ASC 60000L
#define RUNS_ENCODER 1500L
#define RUNS_UNIDRC 200000L
#define RUNS_MPS 1250L

struct Harness {
  const char* name;
  const char* path;
  const char* corpus;
  long runs; /* mutated inputs after the seeds */
};

static const Harness kHarnesses[] = {
    {"fuzz_decoder", BAAC_FUZZ_DECODER, "decoder", RUNS_DECODER},
    {"fuzz_asc", BAAC_FUZZ_ASC, "asc", RUNS_ASC},
    {"fuzz_encoder", BAAC_FUZZ_ENCODER, "encoder", RUNS_ENCODER},
    {"fuzz_unidrc", BAAC_FUZZ_UNIDRC, "unidrc", RUNS_UNIDRC},
    {"fuzz_mps", BAAC_FUZZ_MPS, "mps", RUNS_MPS},
};

/* Starts a harness over its corpus; returns the child's pid. */
static pid_t start(const Harness& h, const std::string& dir, int seconds) {
  const std::string corpus = dir + "/" + h.corpus;
  const std::string artifacts = dir + "/findings";
  const std::string log = dir + "/" + h.name + ".log";
  const pid_t pid = fork();
  if (pid != 0) {
    return pid;
  }
  if (!freopen(log.c_str(), "w", stderr) || !freopen(log.c_str(), "a", stdout)) {
    _exit(126);
  }
  const std::string budget = std::to_string(seconds);
  const std::string runs = std::to_string(h.runs);
#ifdef BAAC_FUZZ_LIBFUZZER
  const std::string time_opt = "-max_total_time=" + budget;
  const std::string runs_opt = "-runs=" + runs;
  const std::string prefix = "-artifact_prefix=" + artifacts + "/";
  const char* argv[] = {h.path,           time_opt.c_str(), runs_opt.c_str(), "-seed=1",
                        "-timeout=20",    "-max_len=65536", "-rss_limit_mb=0", prefix.c_str(),
                        corpus.c_str(),   nullptr};
#else
  const char* argv[] = {h.path,     "--runs",  runs.c_str(),      "--seconds",   budget.c_str(),
                        "--seed",   "1",       "--artifacts",     artifacts.c_str(),
                        corpus.c_str(), nullptr};
#endif
  execv(h.path, (char* const*)argv);
  fprintf(stderr, "cannot start %s\n", h.path);
  _exit(127);
}

int main() {
  const std::string dir = make_temp_dir();
  CHECK(!dir.empty(), "temporary directory");
  if (dir.empty()) {
    return 1;
  }
  const std::string findings = dir + "/findings";
  CHECK(run_command("mkdir -p '" + findings + "'"), "findings directory");
  const std::string gen = std::string("'") + BAAC_FUZZ_SEEDS + "' '" + BAAC_TEST_DATA_DIR + "' '" +
                          dir + "'";
  CHECK(run_command(gen), "seed generation: %s", gen.c_str());
  for (const Harness& h : kHarnesses) {
    const std::string corpus = dir + "/" + h.corpus;
    CHECK(run_command("test -n \"$(ls -A '" + corpus + "')\""), "%s: seeds present", h.name);
  }

  int seconds = 60;
  if (const char* env = getenv("BAAC_FUZZ_SMOKE_SECONDS")) {
    seconds = atoi(env);
  }
  const size_t count = sizeof(kHarnesses) / sizeof(kHarnesses[0]);
  std::vector<pid_t> pids;
  for (const Harness& h : kHarnesses) {
    pids.push_back(start(h, dir, seconds));
  }
  bool failed = false;
  for (size_t i = 0; i < count; i++) {
    int status = 0;
    waitpid(pids[i], &status, 0);
    const bool ok = WIFEXITED(status) && WEXITSTATUS(status) == 0;
    CHECK(ok, "%s: exit status %d, signal %d (log %s/%s.log)", kHarnesses[i].name,
          WIFEXITED(status) ? WEXITSTATUS(status) : -1, WIFSIGNALED(status) ? WTERMSIG(status) : 0,
          dir.c_str(), kHarnesses[i].name);
    if (!ok) {
      failed = true;
    } else {
      /* The last line of the log is the harness's run summary. */
      const std::string show = "tail -n 1 '" + dir + "/" + kHarnesses[i].name + ".log'";
      fflush(stdout);
      if (system(show.c_str()) != 0) {
        printf("%s: no log\n", kHarnesses[i].name);
      }
    }
  }
  if (failed) {
    printf("findings and logs kept under %s\n", dir.c_str());
  } else {
    remove_temp_dir(dir);
  }
  return test_summary("test_fuzz_smoke");
}
