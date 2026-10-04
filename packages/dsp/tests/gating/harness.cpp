// Test-only pool size and exports; production exports remain unchanged.
#define LOUDNESS_GATING_CAPACITY 32
#include "../../loudness_r128/loudness_r128.cpp"

static bool validate_node(uint32_t i, double lower, double upper) {
  if (!i) return true;
  const auto& n = g_integrated.nodes[i];
  const auto& l = g_integrated.nodes[n.left];
  const auto& r = g_integrated.nodes[n.right];
  return n.energy > lower && n.energy < upper
    && std::abs(l.height - r.height) <= 1
    && n.height == 1 + std::max(l.height, r.height)
    && n.total_count == n.count + l.total_count + r.total_count
    && n.sum == n.energy * n.count + l.sum + r.sum
    && validate_node(n.left, lower, n.energy)
    && validate_node(n.right, n.energy, upper);
}

extern "C" {
void gate_add(double energy) { g_integrated.add(energy); }
double gate_sum(double threshold) { return g_integrated.above(threshold).sum; }
double gate_count(double threshold) { return (double)g_integrated.above(threshold).count; }
int gate_used() { return g_integrated.used; }
int gate_exhausted() { return g_integrated.exhausted; }
int gate_valid() { return validate_node(g_integrated.root, 0, std::numeric_limits<double>::infinity()); }
int gate_node_bytes() { return sizeof(GateDistribution::Node); }
}
