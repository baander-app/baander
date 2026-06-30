FROM nginx:alpine

ARG BUILD_ARGUMENT_ENV=dev
ENV ENV=$BUILD_ARGUMENT_ENV

RUN ln -sf /dev/stdout /var/log/nginx/access.log && \
    ln -sf /dev/stderr /var/log/nginx/error.log && \
    rm -rf /etc/nginx/conf.d/*

# --- Development: self-signed certs ---

RUN if [ "$BUILD_ARGUMENT_ENV" = "dev" ]; then \
      apk add --update openssl && \
      rm -rf /var/cache/apk/* && \
      mkdir -p /etc/nginx/certificates && \
      printf '[req]\ndistinguished_name=req\n[x509_extensions]\nsubjectAltName=DNS:baander.test,DNS:*.baander.test,IP:127.0.0.1\n' > /tmp/openssl.cnf && \
      openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/nginx/certificates/key.pem -out /etc/nginx/certificates/cert.pem -subj "/C=DK/ST=Odense/L=Odense/O=Baander/OU=Development/CN=baander.test" -extensions x509_extensions -config /tmp/openssl.cnf && \
      rm /tmp/openssl.cnf; \
    fi

# Copy both dev and prod configs. The RUN step below picks the right one.
COPY ./dev/nginx.conf.template /tmp/nginx-dev.conf
COPY ./prod/nginx.conf /tmp/nginx-prod.conf

RUN mkdir -p /etc/nginx/templates/ && \
    if [ "$BUILD_ARGUMENT_ENV" = "dev" ]; then \
      mv /tmp/nginx-dev.conf /etc/nginx/templates/default.conf.template && \
      rm /tmp/nginx-prod.conf; \
    else \
      mv /tmp/nginx-prod.conf /etc/nginx/conf.d/default.conf && \
      rm /tmp/nginx-dev.conf; \
    fi
