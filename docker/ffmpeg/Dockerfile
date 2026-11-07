# Stage: System dependencies + Rust toolchain (shared base for all builder stages)
FROM buildpack-deps:trixie AS base-builder

RUN set -xe \
    && DEBIAN_FRONTEND=noninteractive apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
      python3 \
      python3-pip \
      python-is-python3 \
      cmake \
      ninja-build \
      meson \
      nasm \
      yasm \
      gettext \
      libfontconfig1-dev \
      libfftw3-dev \
      libva-dev \
      libdrm-dev \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
      | sh -s -- -y --profile minimal --no-modify-path \
    && ln -s /usr/local/cargo/bin/* /usr/local/bin/

# Intermediate stage: shared env for library builds that install to /dist
FROM base-builder AS lib-builder
ENV PKG_CONFIG_PATH=/dist/lib/pkgconfig:/usr/local/lib/x86_64-linux-gnu/pkgconfig:/usr/local/lib/pkgconfig:/usr/local/share/pkgconfig:/usr/lib/x86_64-linux-gnu/pkgconfig:/usr/lib/pkgconfig:/usr/share/pkgconfig \
    CFLAGS=-I/dist/include \
    LDFLAGS=-L/dist/lib

# Stage: Run ffmpeg-build-script to produce the base workspace
FROM base-builder AS ffmpeg-base

WORKDIR /usr/src
RUN git clone --depth 1 --branch v1.59 https://github.com/markus-perl/ffmpeg-build-script.git \
    && cd ffmpeg-build-script \
    && ./build-ffmpeg --build --enable-gpl-and-non-free --skip-install

# ============================================================
# Parallel library stages (BuildKit builds these concurrently)
# Each installs to /dist with lib/, include/, lib/pkgconfig/ layout.
# ============================================================

# --- librist v0.2.13 ---
FROM lib-builder AS librist-builder
WORKDIR /build
RUN git clone --depth 1 --branch v0.2.13 https://code.videolan.org/rist/librist.git \
    && cd librist \
    && meson setup build --prefix=/dist --buildtype=release --default-library=static --libdir=/dist/lib \
        -Dbuiltin_mbedtls=true -Dbuilt_tools=false -Dtest=false \
    && meson compile -C build \
    && meson install -C build

# --- libfribidi v1.0.16 ---
FROM lib-builder AS libfribidi-builder
WORKDIR /build
RUN curl -sL -o fribidi.tar.gz https://github.com/fribidi/fribidi/archive/refs/tags/v1.0.16.tar.gz \
    && mkdir fribidi && tar xzf fribidi.tar.gz -C fribidi --strip-components 1 \
    && cd fribidi \
    && meson setup build --prefix=/dist --buildtype=release --default-library=static --libdir=/dist/lib -Ddocs=false \
    && meson compile -C build \
    && meson install -C build

# --- libharfbuzz 9.0.0 ---
FROM lib-builder AS libharfbuzz-builder
WORKDIR /build
RUN curl -sL -o harfbuzz.tar.gz https://github.com/harfbuzz/harfbuzz/archive/refs/tags/9.0.0.tar.gz \
    && mkdir harfbuzz && tar xzf harfbuzz.tar.gz -C harfbuzz --strip-components 1 \
    && cd harfbuzz \
    && meson setup build --prefix=/dist --buildtype=release --default-library=static --libdir=/dist/lib \
        -Ddocs=disabled -Dtests=disabled -Dbenchmark=disabled -Dglib=disabled \
    && meson compile -C build \
    && meson install -C build

# --- libass 0.17.3 (depends on fribidi + harfbuzz) ---
FROM lib-builder AS libass-builder
COPY --from=libfribidi-builder /dist/ /dist/
COPY --from=libharfbuzz-builder /dist/ /dist/
WORKDIR /build
RUN curl -sL -o libass.tar.xz https://github.com/libass/libass/releases/download/0.17.3/libass-0.17.3.tar.xz \
    && mkdir libass && tar xf libass.tar.xz -C libass --strip-components 1 \
    && cd libass \
    && meson setup build --prefix=/dist --buildtype=release --default-library=static --libdir=/dist/lib \
    && meson compile -C build \
    && meson install -C build

# --- chromaprint 1.5.1 ---
FROM lib-builder AS chromaprint-builder
WORKDIR /build
RUN curl -sL -o chromaprint.tar.gz https://github.com/acoustid/chromaprint/releases/download/v1.5.1/chromaprint-1.5.1.tar.gz \
    && mkdir chromaprint && tar xzf chromaprint.tar.gz -C chromaprint --strip-components 1 \
    && cd chromaprint && mkdir build && cd build \
    && cmake -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/dist -DCMAKE_INSTALL_LIBDIR=lib \
        -DBUILD_SHARED_LIBS=OFF -DBUILD_TOOLS=OFF -DFFT_LIB=fftw3 -DCMAKE_POLICY_VERSION_MINIMUM=3.5 .. \
    && make -j"$(nproc)" \
    && make install \
    && printf 'Libs.private: -lfftw3 -lstdc++ -lm\n' >> /dist/lib/pkgconfig/libchromaprint.pc

# --- libspeex 1.2.1 ---
FROM lib-builder AS libspeex-builder
WORKDIR /build
RUN curl -sL -o speex.tar.gz https://ftp.osuosl.org/pub/xiph/releases/speex/speex-1.2.1.tar.gz \
    && mkdir speex && tar xzf speex.tar.gz -C speex --strip-components 1 \
    && cd speex \
    && ./configure --prefix=/dist --disable-shared --enable-static \
    && make -j"$(nproc)" \
    && make install

# --- libtwolame 0.4.0 ---
FROM lib-builder AS libtwolame-builder
WORKDIR /build
RUN curl -sL -o twolame.tar.gz "https://sourceforge.net/projects/twolame/files/twolame/0.4.0/twolame-0.4.0.tar.gz/download" \
    && mkdir twolame && tar xzf twolame.tar.gz -C twolame --strip-components 1 \
    && cd twolame \
    && ./configure --prefix=/dist --disable-shared --enable-static \
    && make -j"$(nproc)" \
    && make install

# --- libvmaf 3.0.0 ---
FROM lib-builder AS libvmaf-builder
WORKDIR /build
RUN curl -sL -o vmaf.tar.gz https://github.com/Netflix/vmaf/archive/refs/tags/v3.0.0.tar.gz \
    && mkdir vmaf && tar xzf vmaf.tar.gz -C vmaf --strip-components 1 \
    && cd vmaf/libvmaf \
    && meson setup build --prefix=/dist --buildtype=release --default-library=static --libdir=/dist/lib \
    && meson compile -C build \
    && meson install -C build

# --- libaribb24 1.0.3 ---
FROM lib-builder AS libaribb24-builder
WORKDIR /build
RUN curl -sL -o libaribb24.tar.gz https://github.com/nkoriyama/aribb24/archive/refs/tags/v1.0.3.tar.gz \
    && mkdir libaribb24 && tar xzf libaribb24.tar.gz -C libaribb24 --strip-components 1 \
    && cd libaribb24 \
    && autoreconf -fiv \
    && ./configure --prefix=/dist --disable-shared --enable-static \
    && make -j"$(nproc)" \
    && make install

# --- nv-codec-headers 13.0.19.0 ---
# The build script installs these only when nvcc is present, which it is not
# here; NVENC, NVDEC and CUVID need the headers alone.
FROM lib-builder AS nvcodec-builder
WORKDIR /build
RUN curl -sL -o nvcodec.tar.gz https://github.com/FFmpeg/nv-codec-headers/releases/download/n13.0.19.0/nv-codec-headers-13.0.19.0.tar.gz \
    && mkdir nvcodec && tar xzf nvcodec.tar.gz -C nvcodec --strip-components 1 \
    && cd nvcodec \
    && make install PREFIX=/dist

# --- libvpl 2.15.0 (QSV dispatcher; the runtime is loaded on the host) ---
FROM lib-builder AS libvpl-builder
WORKDIR /build
RUN curl -sL -o libvpl.tar.gz https://github.com/intel/libvpl/archive/refs/tags/v2.15.0.tar.gz \
    && mkdir libvpl && tar xzf libvpl.tar.gz -C libvpl --strip-components 1 \
    && cmake -S libvpl -B libvpl/build -G Ninja -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX=/dist -DCMAKE_INSTALL_LIBDIR=lib -DBUILD_SHARED_LIBS=OFF \
        -DBUILD_TOOLS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_TESTS=OFF -DINSTALL_EXAMPLE_CODE=OFF \
    && cmake --build libvpl/build \
    && cmake --install libvpl/build

# --- bAAC (packages/baander-aac, repository checkout) ---
# Installed with the workspace prefix through DESTDIR so that libbaac.pc
# points at the paths FFmpeg's configure sees after the merge below.
FROM lib-builder AS baac-builder
WORKDIR /build
COPY packages/baander-aac /build/baander-aac
RUN cmake -S /build/baander-aac -B /build/baander-aac-build -G Ninja \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX=/usr/src/ffmpeg-build-script/workspace \
        -DCMAKE_INSTALL_LIBDIR=lib \
        -DBAAC_AAC_BUILD_TESTS=OFF -DBAAC_AAC_BUILD_TOOLS=OFF -DBAAC_AAC_SHARED=OFF \
    && cmake --build /build/baander-aac-build \
    && DESTDIR=/dist cmake --install /build/baander-aac-build \
    && PKG_CONFIG_PATH=/dist/usr/src/ffmpeg-build-script/workspace/lib/pkgconfig \
       PKG_CONFIG_SYSROOT_DIR=/dist pkg-config --static --libs libbaac

# ============================================================
# FFmpeg final: merge all library artifacts and rebuild
# ============================================================
# ffmpeg-prepared holds the merged workspace and the patched tree inputs
# without running configure, so a failing configure can be reproduced in a
# container built from this stage.
FROM ffmpeg-base AS ffmpeg-prepared

# Merge library artifacts into workspace (COPY adds files, does not clobber)
COPY --from=librist-builder    /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libass-builder     /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=chromaprint-builder /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libspeex-builder   /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libtwolame-builder /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libvmaf-builder    /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libaribb24-builder /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=nvcodec-builder    /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=libvpl-builder     /dist/ /usr/src/ffmpeg-build-script/workspace/
COPY --from=baac-builder       /dist/usr/src/ffmpeg-build-script/workspace/ /usr/src/ffmpeg-build-script/workspace/

# The bAAC wrapper: sources copied into the FFmpeg tree, the registration
# patch checked before it is applied so that drift fails the build.
COPY docker/ffmpeg/baac /usr/src/baac
COPY docker/ffmpeg/smoke.sh /usr/src/smoke.sh

FROM ffmpeg-prepared AS ffmpeg-final
RUN set -xe \
    && WORKSPACE=/usr/src/ffmpeg-build-script/workspace \
    && export PKG_CONFIG_PATH="$WORKSPACE/lib/pkgconfig:/usr/local/lib/x86_64-linux-gnu/pkgconfig:/usr/local/lib/pkgconfig:/usr/local/share/pkgconfig:/usr/lib/x86_64-linux-gnu/pkgconfig:/usr/lib/pkgconfig:/usr/share/pkgconfig:/usr/lib64/pkgconfig" \
    && sed -i "s#/dist#$WORKSPACE#g" "$WORKSPACE"/lib/pkgconfig/*.pc \
    && FFMPEG_SRC="$(ls -d /usr/src/ffmpeg-build-script/packages/FFmpeg-* | head -1)" \
    && cd "$FFMPEG_SRC" \
    && printf '%s\n' "${FFMPEG_SRC##*-}" > VERSION \
    && cp /usr/src/baac/libavcodec/*.c libavcodec/ \
    && git apply --check -p1 /usr/src/baac/ffmpeg-8.1-libbaac.patch \
    && git apply -p1 /usr/src/baac/ffmpeg-8.1-libbaac.patch \
    && FFMPEG_CONFIGURATION="$(grep '^FFMPEG_CONFIGURATION=' ffbuild/config.mak | cut -d= -f2-)" \
    && { make distclean || true; } \
    && eval "./configure $FFMPEG_CONFIGURATION \
        --enable-libbaac \
        --extra-libs=-lstdc++ \
        --enable-vaapi \
        --enable-libdrm \
        --disable-xlib \
        --disable-libxcb \
        --enable-ffnvcodec \
        --enable-nvenc \
        --enable-nvdec \
        --enable-cuvid \
        --enable-libvpl \
        --enable-libfribidi \
        --enable-libharfbuzz \
        --enable-libass \
        --enable-chromaprint \
        --enable-libspeex \
        --enable-libtwolame \
        --enable-libvmaf \
        --enable-librist \
        --enable-libaribb24" \
    && make -j"$(nproc)" \
    && make install

# The smoke runs in its own layer so that a change to it does not rebuild FFmpeg.
RUN set -xe \
    && WORKSPACE=/usr/src/ffmpeg-build-script/workspace \
    && SMOKE_EXPECT_FDK=1 bash /usr/src/smoke.sh "$WORKSPACE/bin/ffmpeg" "$WORKSPACE/bin/ffprobe" /tmp/smoke

# ============================================================
# Runtime image
# ============================================================
FROM debian:trixie-slim

# The binaries link Debian's shared libva, libdrm and the libraries behind
# the builder's -dev packages; the list is what ldd on the built binaries
# reports, and the gate proves it resolves.
COPY docker/ffmpeg/runtime-packages.txt docker/ffmpeg/expected-capabilities.txt /opt/baander/
COPY scripts/ffmpeg-capability-gate.sh /usr/local/bin/ffmpeg-capability-gate
COPY --from=ffmpeg-final /usr/src/ffmpeg-build-script/workspace/bin/ffmpeg /usr/local/bin/ffmpeg
COPY --from=ffmpeg-final /usr/src/ffmpeg-build-script/workspace/bin/ffprobe /usr/local/bin/ffprobe

RUN set -xe \
    && DEBIAN_FRONTEND=noninteractive apt-get update -qq \
    && grep -v '^#' /opt/baander/runtime-packages.txt | xargs apt-get install -yqq --no-install-recommends \
    && rm -rf /var/lib/apt/lists/* \
    && chmod +x /usr/local/bin/ffmpeg /usr/local/bin/ffprobe /usr/local/bin/ffmpeg-capability-gate \
    && ffmpeg-capability-gate /usr/local/bin/ffmpeg /opt/baander/expected-capabilities.txt

ENTRYPOINT ["/usr/local/bin/ffmpeg"]
