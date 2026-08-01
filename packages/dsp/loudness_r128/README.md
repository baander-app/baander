Loudness (EBU R128-like) module
- Implements K-weighting (pre-filter + RLB), rolling windows for momentary (400 ms) and short-term (3 s), running integrated LUFS with simple absolute and relative gating, LRA over a sliding buffer, and a simple true-peak estimator using 4× oversampling (linear).
- It’s a pragmatic implementation suitable for real-time UI and normalization; for full broadcast compliance, you might refine gating and percentiles.
