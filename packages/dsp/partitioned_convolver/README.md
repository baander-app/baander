Block-based convolution for long IRs.

For clarity, this demo implements a straightforward overlap-add per block (time-domain).
It’s easy to swap for true partitioned FFT later without changing the ABI.
