/* The codec's computed tables are built when the first encoder or
 * decoder is created, not when the library is loaded.  Run with the
 * argument "decoders" or "both", the first use is made by decoders of
 * the committed HE-AAC streams, alone or beside encoders; without an
 * argument it is made by encoders, as follows.  This program is
 * linked without the at-load initialisation the other suites have, and
 * the first thing it does is create encoders of every profile from
 * several threads at the same moment: whichever thread wins builds the
 * tables, the others have to wait for it, and every one of them has to
 * encode what a single thread encodes afterwards, byte for byte.  The
 * decoders then start the same way in a second process state: the
 * streams of the profiles ADTS carries are decoded on all threads at
 * once and have to agree with a decode on one. */
#include <atomic>
#include <thread>

#include "test_util.h"

struct Profile {
  const char* name;
  AacObjectType aot;
  int rate;
  int channels;
  int bitrate;
  bool adts; /* the default transport is ADTS, which decode_stream splits */
};

static const Profile kProfiles[] = {
    {"AAC-LC", AAC_AOT_LC, 48000, 2, 128000, true},
    {"HE-AAC v1", AAC_AOT_SBR, 48000, 2, 64000, true},
    {"HE-AAC v2", AAC_AOT_PS, 48000, 2, 32000, true},
    {"AAC-LD", AAC_AOT_ER_LD, 48000, 2, 64000, false},
    {"AAC-ELD", AAC_AOT_ER_ELD, 48000, 2, 64000, false},
    {"xHE-AAC", AAC_AOT_USAC, 48000, 2, 96000, false},
};
static const int kProfileCount = (int)(sizeof(kProfiles) / sizeof(kProfiles[0]));
static const int kThreads = 12;

static std::vector<uint8_t> encode(const Profile& p, const std::vector<float>& pcm) {
  int error = AAC_OK;
  return encode_stream(pcm, p.rate, p.channels, p.bitrate, AAC_RC_CBR, 0, &error, p.aot);
}

/* The committed HE-AAC streams, v1 and v2: decoders for them use every
 * computed table and the hybrid filters of parametric stereo. */
static const char* const kCommitted[] = {"he_stereo_40k.aac", "hev2_stereo_32k.aac"};

/* A decoder as the first user of the tables, and decoders with encoders
 * as the first users: `with_encoders` puts an encoder on every second
 * thread.  Each thread's result has to be the one a single thread gets
 * afterwards. */
static int first_use_by_decoders(bool with_encoders) {
  /* Reading the files and making the signal touches no table. */
  std::vector<std::vector<uint8_t>> committed;
  for (const char* name : kCommitted) {
    committed.push_back(read_file(data_path(name)));
    CHECK(!committed.back().empty(), "%s is there", name);
  }
  const std::vector<float> pcm = gen_tones(24000, 48000, 2);

  std::atomic<bool> go(false);
  std::vector<std::vector<float>> decoded((size_t)kThreads);
  std::vector<std::vector<uint8_t>> encoded((size_t)kThreads);
  std::vector<int> errors((size_t)kThreads, 0);
  std::vector<std::thread> threads;
  const auto encodes = [with_encoders](int t) { return with_encoders && (t & 1); };
  for (int t = 0; t < kThreads; t++) {
    threads.emplace_back([&, t] {
      while (!go.load(std::memory_order_acquire)) {
      }
      if (encodes(t)) {
        encoded[(size_t)t] = encode(kProfiles[(t / 2) % 3], pcm);
      } else {
        decoded[(size_t)t] =
            decode_stream(committed[(size_t)(t / 2) % 2], 48000, 0, &errors[(size_t)t]);
      }
    });
  }
  go.store(true, std::memory_order_release);
  for (std::thread& th : threads) {
    th.join();
  }
  for (int t = 0; t < kThreads; t++) {
    if (encodes(t)) {
      const Profile& p = kProfiles[(t / 2) % 3];
      const std::vector<uint8_t> alone = encode(p, pcm);
      CHECK(!alone.empty() && encoded[(size_t)t] == alone,
            "%s: the encoder that started among %d threads, decoders with it, writes the stream "
            "one thread writes (%zu bytes against %zu)",
            p.name, kThreads, encoded[(size_t)t].size(), alone.size());
      continue;
    }
    const char* name = kCommitted[(t / 2) % 2];
    int alone_errors = 0;
    const std::vector<float> alone =
        decode_stream(committed[(size_t)(t / 2) % 2], 48000, 0, &alone_errors);
    CHECK(errors[(size_t)t] == 0 && alone_errors == 0 && !alone.empty(),
          "%s decodes (%d and %d frames failed)", name, errors[(size_t)t], alone_errors);
    CHECK(decoded[(size_t)t].size() == alone.size() &&
              memcmp(decoded[(size_t)t].data(), alone.data(), alone.size() * sizeof(float)) == 0,
          "%s: the decoder that made the first use of the tables among %d threads returns the "
          "samples one thread returns",
          name, kThreads);
  }
  return test_summary(with_encoders ? "test_tables_lazy both" : "test_tables_lazy decoders");
}

/* Without an argument the first use is by encoders of every profile;
 * "decoders" and "both" are the other two ways a process can begin, and
 * each needs a process of its own. */
int main(int argc, char** argv) {
  if (argc > 1) {
    return first_use_by_decoders(strcmp(argv[1], "both") == 0);
  }
  /* The signal is made before anything of the codec runs; making it
   * touches no table. */
  const std::vector<float> pcm = gen_tones(24000, 48000, 2);

  std::atomic<bool> go(false);
  std::vector<std::vector<uint8_t>> streams((size_t)kThreads);
  std::vector<std::thread> threads;
  for (int t = 0; t < kThreads; t++) {
    threads.emplace_back([&, t] {
      while (!go.load(std::memory_order_acquire)) {
      }
      streams[(size_t)t] = encode(kProfiles[t % kProfileCount], pcm);
    });
  }
  go.store(true, std::memory_order_release);
  for (std::thread& th : threads) {
    th.join();
  }
  for (int t = 0; t < kThreads; t++) {
    const Profile& p = kProfiles[t % kProfileCount];
    const std::vector<uint8_t> alone = encode(p, pcm);
    CHECK(!alone.empty(), "%s encodes", p.name);
    CHECK(streams[(size_t)t] == alone,
          "%s: the encoder that started among %d threads writes the stream one thread writes "
          "(%zu bytes against %zu)",
          p.name, kThreads, streams[(size_t)t].size(), alone.size());
  }

  /* The decoders, started together as well. */
  std::vector<std::vector<float>> decoded((size_t)kThreads);
  std::vector<int> errors((size_t)kThreads, 0);
  std::atomic<bool> go_decode(false);
  threads.clear();
  for (int t = 0; t < kThreads; t++) {
    threads.emplace_back([&, t] {
      while (!go_decode.load(std::memory_order_acquire)) {
      }
      const Profile& p = kProfiles[t % 3];
      decoded[(size_t)t] =
          decode_stream(streams[(size_t)(t % 3)], p.rate, p.channels, &errors[(size_t)t]);
    });
  }
  go_decode.store(true, std::memory_order_release);
  for (std::thread& th : threads) {
    th.join();
  }
  for (int t = 0; t < kThreads; t++) {
    const Profile& p = kProfiles[t % 3];
    int alone_errors = 0;
    const std::vector<float> alone =
        decode_stream(streams[(size_t)(t % 3)], p.rate, p.channels, &alone_errors);
    CHECK(errors[(size_t)t] == 0 && alone_errors == 0 && alone.size() >= pcm.size(),
          "%s decodes (%d and %d frames failed)", p.name, errors[(size_t)t], alone_errors);
    CHECK(decoded[(size_t)t].size() == alone.size() &&
              memcmp(decoded[(size_t)t].data(), alone.data(), alone.size() * sizeof(float)) == 0,
          "%s: the decoder that started among %d threads returns the samples one thread returns",
          p.name, kThreads);
  }
  return test_summary("test_tables_lazy");
}
