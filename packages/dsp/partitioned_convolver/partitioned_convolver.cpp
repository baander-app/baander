#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <algorithm>
#include <vector>

extern "C" {

struct Conv {
  int block = 256;
  int ch = 1;
  std::vector<float> ir;           // impulse
  int ir_len = 0;
  std::vector<float> overlap;      // per-channel overlap buffer of size ir_len-1
};

void* create_convolver(const float* ir, int ir_length, int block_size, int channels) {
  if (!ir || ir_length <= 0 || block_size <= 0 || channels <= 0) return nullptr;
  Conv* cv = new Conv();
  cv->block = block_size;
  cv->ch = channels;
  cv->ir_len = ir_length;
  cv->ir.assign(ir, ir + ir_length);
  cv->overlap.assign((ir_length - 1) * channels, 0.0f);
  return (void*)cv;
}

void destroy_convolver(void* ctx) {
  if (!ctx) return;
  delete (Conv*)ctx;
}

// Time-domain block convolution with overlap-add per channel
void process(void* ctx, const float* in, float* out, int frames) {
  if (!ctx || !in || !out || frames <= 0) return;
  Conv* cv = (Conv*)ctx;
  const int C = cv->ch;
  const int L = cv->ir_len;

  // Process in blocks
  int processed = 0;
  while (processed < frames) {
    int n = std::min(cv->block, frames - processed);

    for (int c=0;c<C;c++) {
      const float* x = in  + (processed*C + c);
      float*       y = out + (processed*C + c);

      // temp output for block
      std::vector<float> temp(n + L - 1, 0.0f);

      // Convolution: y = x (*) h
      for (int i=0;i<n;i++) {
        float xi = x[i*C];
        for (int k=0;k<L;k++) {
          temp[i + k] += xi * cv->ir[k];
        }
      }

      // Add overlap and write
      float* ov = cv->overlap.data() + c*(L-1);
      for (int i=0;i<n;i++) {
        float v = temp[i] + ov[0];
        y[i*C] = v;
        // shift overlap for next sample? We'll manage after loop
      }

      // Prepare next overlap: tail n..n+L-2 becomes new overlap
      // First shift old overlap down by n
      // Instead of shifting per sample, directly compute new overlap from temp
      for (int j=0;j<L-1;j++) {
        int idx = n + j;
        ov[j] = temp[idx];
      }
    }

    processed += n;
  }
}

} // extern "C"
