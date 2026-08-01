FROM debian:trixie-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        build-essential \
        cmake \
        pkg-config \
        g++ \
        python3 \
        python3-pip \
        openjdk-21-jdk-headless \
        unzip \
        jq \
        tree \
        procps \
        zstd \
    && rm -rf /var/lib/apt/lists/*

# Official Docker CLI
RUN curl -fsSL https://download.docker.com/linux/debian/gpg \
        | gpg --dearmor -o /usr/share/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-buildx-plugin \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && corepack enable

# Emscripten SDK
RUN git clone https://github.com/emscripten-core/emsdk.git /opt/emsdk \
    && /opt/emsdk/emsdk install latest \
    && /opt/emsdk/emsdk activate latest \
    && echo '. /opt/emsdk/emsdk_env.sh' >> /etc/bash.bashrc

ENV PATH="/opt/emsdk:/opt/emsdk/upstream/bin:${PATH}"

# Verify toolchain
RUN cd /opt/emsdk && . ./emsdk_env.sh \
    && node --version \
    && javac --version \
    && docker --version \
    && docker buildx version \
    && g++ --version \
    && cmake --version \
    && emcc --version
