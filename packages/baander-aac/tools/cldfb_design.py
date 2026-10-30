#!/usr/bin/env python3
"""Designs the low-delay prototype windows of the CLDFB in src/qmf_ld.cpp.

The low-delay complex filterbank keeps the structure of the SBR QMF banks
(a 10 B tap window folded into 2 B values and modulated by
exp(i pi / (2 B) (k + 1/2)(2 n - d))) and differs from them in the windows:
instead of one symmetric prototype, each bank has its own asymmetric
window (analysis and synthesis, 64 and 32 bands) and the chains
reconstruct the input with a delay far below the symmetric bank's 9 B
samples.

The windows are found by alternating least squares: with the other
windows fixed every chain's output is linear in one window, so each step
is a regularized least-squares fit of the outputs of the chains that use
that window to the delayed input over random excitations, with a
stopband-energy penalty that keeps the window a lowpass.  The fit covers
the four pairings the codec uses: 64 to 64 bands, 32 to 64 for the
dual-rate decoder, 64 to 32 for the encoder's resampler and 32 to 32 for
single-rate SBR.  The delay must be congruent to 65 modulo 128 for the
modulation's phase constants to cancel the aliasing.

    python3 tools/cldfb_design.py DELAY LAMBDA ITERATIONS FAR_WEIGHT \
        CROSS_WEIGHT > src/qmf_ld_window.inc

The cross-rate pairings weigh less (CROSS_WEIGHT) because their output
also carries the images around the half-rate Nyquist frequency, which no
bank of this length rejects fully and which the codec covers with SBR
bands or does not produce.
"""
import sys

import numpy as np

M = 64
L = 10 * M
DELAY = int(sys.argv[1]) if len(sys.argv) > 1 else 2 * M + M // 2  # 64->64 chain delay
LAMBDA = float(sys.argv[2]) if len(sys.argv) > 2 else 1e-3
ITER = int(sys.argv[3]) if len(sys.argv) > 3 else 40
FAR_WEIGHT = float(sys.argv[4]) if len(sys.argv) > 4 else 100.0
CROSS_WEIGHT = float(sys.argv[5]) if len(sys.argv) > 5 else 0.3
rng = np.random.default_rng(1)


def ana_tables(B):
    n = np.arange(2 * B)
    k = np.arange(B)[:, None]
    if B == 32:
        a = np.pi / 64 * (k + 0.5) * (2 * n - 0.5)
        return 2 * np.cos(a), 2 * np.sin(a)
    a = np.pi / 128 * (k + 0.5) * (2 * n - 1.0)
    return np.cos(a), np.sin(a)


def syn_tables(B):
    n = np.arange(2 * B)[:, None]
    k = np.arange(B)
    if B == 32:
        a = np.pi / 64 * (k + 0.5) * (2 * n - 127.5)
    else:
        a = np.pi / 128 * (k + 0.5) * (2 * n - 255.0)
    return np.cos(a) / 64, np.sin(a) / 64


def analysis_u(x, B):
    """Folded windowed histories: U[slot, m] = x_hist(m) for m < 10 B, the
    window applied later (linear in the window)."""
    nslots = len(x) // B
    hist = np.zeros(10 * B)
    U = np.zeros((nslots, 10 * B))
    for s in range(nslots):
        hist = np.roll(hist, B)
        hist[:B] = x[s * B:(s + 1) * B][::-1]
        U[s] = hist
    return U


def synth_v_to_out(V, B, ws):
    """V[slot, 0..2B) new blocks -> output samples with window ws (10 B)."""
    nslots = V.shape[0]
    out = np.zeros(nslots * B)
    # v ring: newest block first (as in qmf.cpp): index into stacked history
    hist = np.zeros(20 * B)
    for s in range(nslots):
        hist = np.roll(hist, 2 * B)
        hist[:2 * B] = V[s]
        for j in range(5):
            g0 = 4 * B * j + np.arange(B)
            g1 = 4 * B * j + 3 * B + np.arange(B)
            out[s * B:(s + 1) * B] += hist[g0] * ws[2 * j * B + np.arange(B)] + \
                hist[g1] * ws[(2 * j + 1) * B + np.arange(B)]
    return out


def synth_matrix_rows(V, B):
    """Rows A such that out = A @ ws for the window ws of length 10 B."""
    nslots = V.shape[0]
    A = np.zeros((nslots * B, 10 * B))
    hist = np.zeros(20 * B)
    for s in range(nslots):
        hist = np.roll(hist, 2 * B)
        hist[:2 * B] = V[s]
        for j in range(5):
            g0 = 4 * B * j + np.arange(B)
            g1 = 4 * B * j + 3 * B + np.arange(B)
            rows = s * B + np.arange(B)
            A[rows, 2 * j * B + np.arange(B)] += hist[g0]
            A[rows, (2 * j + 1) * B + np.arange(B)] += hist[g1]
    return A


def chain_W(x, Ba, wa):
    U = analysis_u(x, Ba) * wa[None, :]
    u = U.reshape(U.shape[0], 5, 2 * Ba).sum(axis=1)  # [slot, 2B]
    c, s = ana_tables(Ba)
    return u @ c.T, u @ s.T  # re, im [slot, B]


def V_from_W(Wre, Wim, Bs):
    c, s = syn_tables(Bs)
    X_re = np.zeros((Wre.shape[0], Bs))
    X_im = np.zeros((Wre.shape[0], Bs))
    nb = min(Wre.shape[1], Bs)
    X_re[:, :nb] = Wre[:, :nb]
    X_im[:, :nb] = Wim[:, :nb]
    return X_re @ c.T - X_im @ s.T  # [slot, 2B]


def band_energy_matrix(L_, edge):
    """Q with w^T Q w = integral of |W(e^jw)|^2 over [edge, pi]."""
    m = np.arange(L_)
    d = m[:, None] - m[None, :]
    with np.errstate(divide='ignore', invalid='ignore'):
        return np.where(d == 0, np.pi - edge, -np.sin(d * edge) / d)


def block_signs(B, L_):
    """The windows are stored, like the SBR prototype, with the sign of
    every second block of 2 B coefficients flipped (the modulation absorbs
    it); the lowpass prototype is the window times these signs."""
    return (-1.0) ** (np.arange(L_) // (2 * B))


def stopband_matrix(B, L_):
    """Stopband penalty on the prototype: from twice the cutoff pi/(2B),
    with the far stopband (from four times the cutoff, where the images of
    the half-rate banks land) weighted FAR_WEIGHT times more."""
    Q = band_energy_matrix(L_, np.pi / B) + FAR_WEIGHT * band_energy_matrix(L_, 2 * np.pi / B)
    sg = block_signs(B, L_)
    return Q * sg[:, None] * sg[None, :]


def chain_output_rows_for_analysis(x, Ba, Bs, ws):
    """Rows R with out = R @ wa for the analysis window wa of 10 Ba taps."""
    U = analysis_u(x, Ba)  # [slot, 10 Ba]
    nslots = U.shape[0]
    c, s = ana_tables(Ba)
    cs, ss = syn_tables(Bs)
    cm = np.tile(c, (1, 5))  # [Ba, 10 Ba]
    sm = np.tile(s, (1, 5))
    nb = min(Ba, Bs)
    T = cs[:, :nb] @ cm[:nb] - ss[:, :nb] @ sm[:nb]  # [2Bs, 10Ba]
    Vm = U[:, None, :] * T[None, :, :]  # [slot, 2Bs, 10Ba]
    R = np.zeros((nslots * Bs, 10 * Ba))
    hist = np.zeros((20 * Bs, 10 * Ba))
    for sl in range(nslots):
        hist = np.roll(hist, 2 * Bs, axis=0)
        hist[:2 * Bs] = Vm[sl]
        for j in range(5):
            g0 = 4 * Bs * j + np.arange(Bs)
            g1 = 4 * Bs * j + 3 * Bs + np.arange(Bs)
            rows = sl * Bs + np.arange(Bs)
            R[rows] += hist[g0] * ws[2 * j * Bs + np.arange(Bs)][:, None] + \
                hist[g1] * ws[(2 * j + 1) * Bs + np.arange(Bs)][:, None]
    return R


def excitation(n, lowpass=False):
    x = rng.standard_normal(n)
    if lowpass:
        f = np.fft.rfft(x)
        f[int(0.42 * len(f)):] = 0.0  # below 0.42 pi: representable at half rate
        x = np.fft.irfft(f, n)
    return x


# The four pairings.  Delays are in output samples; d32_64 counts at the
# full rate, d64_32 pairs output i with the odd input sample 2 i + 1.
def chain_delays(D):
    return {'64->64': D, '32->64': D + 1, '32->32': (D + 1) // 2, '64->32': (D + 1) // 2}


CHAIN_WEIGHTS = {'64->64': 1.0, '32->64': CROSS_WEIGHT, '32->32': 1.0, '64->32': CROSS_WEIGHT}


def chain_reference(name, x, delays):
    d = delays[name]
    if name == '64->64':
        return np.concatenate([np.zeros(d), x])[:len(x)]
    if name == '32->64':
        # x is the full-rate lowpass signal whose even samples feed the bank
        return np.concatenate([np.zeros(d), x])[:len(x)]
    if name == '32->32':
        return np.concatenate([np.zeros(d), x])[:len(x)]
    xo = x[1::2]
    return np.concatenate([np.zeros(d), xo])[:len(xo)]


def chain_bands(name):
    a, b = name.split('->')
    return int(a), int(b)


def chain_input(name, n):
    Ba, Bs = chain_bands(name)
    if Ba == 64:
        return excitation(n, lowpass=(Bs == 32))
    if Bs == 64:
        return excitation(n, lowpass=True)  # the bank takes its even samples
    return excitation(n // 2)


def window_names(name):
    Ba, Bs = chain_bands(name)
    return 'a%d' % Ba, 's%d' % Bs


def fit_window(wn, win, lam, delays, n=64 * 80):
    """One least-squares step for window `wn` given the others."""
    B = int(wn[1:])
    Lw = 10 * B
    Q = stopband_matrix(B, Lw)
    rows, rhs = [], []
    for name, wgt in CHAIN_WEIGHTS.items():
        an, sn = window_names(name)
        if wgt <= 0 or wn not in (an, sn):
            continue
        Ba, Bs = chain_bands(name)
        x = chain_input(name, n)
        xin = x[0::2] if name == '32->64' else x
        if wn == sn:
            Wre, Wim = chain_W(xin, Ba, win[an])
            A = synth_matrix_rows(V_from_W(Wre, Wim, Bs), Bs)
        else:
            A = chain_output_rows_for_analysis(xin, Ba, Bs, win[sn])
        ref = chain_reference(name, x, delays)
        skip = 20 * Bs  # warm-up rows
        rows.append(np.sqrt(wgt) * A[skip:])
        rhs.append(np.sqrt(wgt) * ref[skip:])
    A = np.vstack(rows)
    b = np.concatenate(rhs)
    AtA = A.T @ A
    # the penalty's weight is relative to the fit's, whatever the scale of
    # the window being fitted
    H = AtA + lam * np.trace(AtA) / np.trace(Q) * Q
    return np.linalg.solve(H, A.T @ b)


def chain_run(name, x, win):
    Ba, Bs = chain_bands(name)
    an, sn = window_names(name)
    if name == '32->64':
        x = x[0::2]
    Wre, Wim = chain_W(x, Ba, win[an])
    return synth_v_to_out(V_from_W(Wre, Wim, Bs), Bs, win[sn])


def stopband_db(w, B):
    f = np.fft.rfft(w * block_signs(B, len(w)), 65536)
    mag = 20 * np.log10(np.abs(f) / np.abs(f).max() + 1e-20)
    return mag[int(65536 / 2 / B):].max()


def evaluate(win, delays, search=False):
    """SNR per chain at its delay (or the best delay within +-64), and the
    largest stopband level of each window from twice its cutoff."""
    out = {}
    n = 64 * 200
    for name in CHAIN_WEIGHTS:
        Ba, Bs = chain_bands(name)
        x = chain_input(name, n)
        y = chain_run(name, x, win)
        cands = range(max(0, delays[name] - 64), delays[name] + 65) if search else [delays[name]]
        best = (-1e9, None)
        for d in cands:
            dd = dict(delays)
            dd[name] = d
            ref = chain_reference(name, x, dd)
            lo = 40 * Bs
            r = ref[lo:]
            e = y[lo:] - r
            v = 10 * np.log10(np.sum(r ** 2) / max(np.sum(e ** 2), 1e-30))
            if v > best[0]:
                best = (v, d)
        out[name] = best if search else best[0]
    for wn, w in win.items():
        out[wn + '_stop'] = stopband_db(w, int(wn[1:]))
    return out


def main():
    delays = chain_delays(DELAY)
    # Start from a lowpass whose centre sits where a window of the target
    # delay has its group delay, with an asymmetric taper around it.
    n = np.arange(L)
    c = (DELAY + 62) / 2.0
    taper = np.where(n <= c, np.sin(np.pi / 2 * (n + 0.5) / (c + 0.5)),
                     np.cos(np.pi / 2 * (n - c) / (L - c))) ** 2
    w = np.sinc((n - c) / 128) * taper * block_signs(64, L)
    win = {'a64': w.copy(), 's64': w.copy(), 'a32': w[0::2].copy(), 's32': w[0::2].copy()}
    for it in range(ITER):
        for wn in ('s64', 's32', 'a64', 'a32'):
            win[wn] = fit_window(wn, win, LAMBDA, delays)
        if it % 5 == 4 or it == ITER - 1:
            ev = evaluate(win, delays)
            print(f"# iter {it + 1}: " + ", ".join(f"{k}={v:.1f}" for k, v in ev.items()),
                  file=sys.stderr, flush=True)
    print("#", evaluate(win, delays, search=True), file=sys.stderr)
    print("/* Generated by tools/cldfb_design.py %d %g %d %g %g (delay, stopband weight,"
          % (DELAY, LAMBDA, ITER, FAR_WEIGHT, CROSS_WEIGHT))
    print(" * iterations, far stopband weight, cross-rate weight). */")
    for wn, cname in (('a64', 'kCldfbAnalysis64'), ('s64', 'kCldfbSynthesis64'),
                      ('a32', 'kCldfbAnalysis32'), ('s32', 'kCldfbSynthesis32')):
        w = win[wn]
        print("static const float %s[%d] = {" % (cname, len(w)))
        for i in range(0, len(w), 4):
            print("    " + ", ".join("%.9ef" % v for v in w[i:i + 4]) + ",")
        print("};")


if __name__ == '__main__':
    main()
