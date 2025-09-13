FROM debian:bookworm-slim AS builder

RUN set -xe \
    && DEBIAN_FRONTEND=noninteractive apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get upgrade -yqq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
      build-essential \
      ca-certificates \
      curl \
      python3 \
      python-is-python3 \
      ninja-build \
      meson \
      git \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

WORKDIR /usr/src

RUN set -xe \
    && git clone --depth 1 https://github.com/markus-perl/ffmpeg-build-script.git \
    && cd ffmpeg-build-script \
    && ./build-ffmpeg --enable-gpl-and-non-free --build --full-static

#COPY --from=builder /usr/src/ffmpeg-build-script/bin/ffmpeg /ffmpeg
#COPY --from=builder /usr/src/ffmpeg-build-script/bin/ffprobe /ffprobe
#COPY --from=builder /usr/src/ffmpeg-build-script/bin/ffplay /ffplay
#
#CMD         ["--help"]
#ENTRYPOINT  ["/ffmpeg"]
