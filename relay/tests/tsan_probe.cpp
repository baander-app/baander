// SPDX-License-Identifier: Apache-2.0
// Deliberately racy positive control: only the verifier may execute this target.
#include <atomic>
#include <thread>

int main() {
    std::atomic<bool> ready{false};
    volatile int shared = 0;
    std::thread first([&] {
        while (!ready.load(std::memory_order_acquire))
            std::this_thread::yield();
        for (int i = 0; i < 10000; ++i)
            shared = 1;
    });
    std::thread second([&] {
        while (!ready.load(std::memory_order_acquire))
            std::this_thread::yield();
        for (int i = 0; i < 10000; ++i)
            shared = 2;
    });
    ready.store(true, std::memory_order_release);
    first.join();
    second.join();
    return shared == 0 ? 1 : 0;
}
