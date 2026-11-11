/* The lossless mode of the baac tool (baac_flac.cpp), as baac.cpp calls
 * it. */
#ifndef BAANDER_AAC_TOOLS_BAAC_FLAC_H
#define BAANDER_AAC_TOOLS_BAAC_FLAC_H

#include <string>
#include <vector>

/* Runs `baac flac <command> ...`: the arguments behind "flac".  Returns
 * the exit status. */
int baac_flac_main(int argc, char** argv);

/* The lines of the usage text for the lossless commands. */
const char* baac_flac_usage(void);

/* The lossless rows of `baac bench` on the bench signal (interleaved
 * stereo float).  Returns the number of rows that failed. */
int baac_flac_bench(const std::vector<float>& pcm, int sample_rate, int seconds,
                    const std::string& only, const std::string& dump);

#endif /* BAANDER_AAC_TOOLS_BAAC_FLAC_H */
