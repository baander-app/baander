/* The lossless mode of the bac tool (bac_flac.cpp), as bac.cpp calls
 * it. */
#ifndef BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_H
#define BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_H

#include <string>
#include <vector>

/* Runs `bac flac <command> ...`: the arguments behind "flac".  Returns
 * the exit status. */
int bac_flac_main(int argc, char** argv);

/* The lines of the usage text for the lossless commands. */
const char* bac_flac_usage(void);

/* The lossless rows of `bac bench` on the bench signal (interleaved
 * stereo float).  Returns the number of rows that failed. */
int bac_flac_bench(const std::vector<float>& pcm, int sample_rate, int seconds,
                    const std::string& only, const std::string& dump);

#endif /* BAANDER_AUDIO_CORE_TOOLS_BAC_FLAC_H */
