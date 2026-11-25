FROM ubuntu:26.04

LABEL org.opencontainers.image.title="Offprintless" \
      org.opencontainers.image.description="Self-hosted reference manager for PDFs and bibliographic metadata, with project-based access. A fork of I, Librarian Free." \
      org.opencontainers.image.version="5.11.3" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.source="https://github.com/wenjie2wang/offprintless" \
      org.opencontainers.image.base.name="ubuntu:26.04"

ARG TIMEZONE="UTC"
ARG CADDY_VERSION="2.11.9"
ARG TESSERACT_ALL_LANGS=false

RUN TESS_PKG="tesseract-ocr tesseract-ocr-ell"; \
    if [ "$TESSERACT_ALL_LANGS" = "true" ]; then TESS_PKG="tesseract-ocr-all"; fi; \
    apt-get update && \
    DEBIAN_FRONTEND=noninteractive TZ=${TIMEZONE} apt-get install -qy --no-install-recommends \
    ca-certificates wget tzdata php-fpm php-mbstring php-sqlite3 php-curl php-gd php-xml \
    php-zip php-intl php-ldap ghostscript \
    poppler-utils $TESS_PKG && \
    case "$(uname -m)" in \
      x86_64)  CADDY_ARCH="amd64" ;; \
      aarch64) CADDY_ARCH="arm64" ;; \
      *) echo "Architecture $(uname -m) is not supported" && exit 1 ;; \
    esac && \
    wget https://github.com/caddyserver/caddy/releases/download/v${CADDY_VERSION}/caddy_${CADDY_VERSION}_linux_${CADDY_ARCH}.tar.gz && \
    tar xzf caddy_${CADDY_VERSION}_linux_${CADDY_ARCH}.tar.gz caddy && \
    chown root:root caddy && \
    mv caddy /usr/bin/caddy && \
    groupadd --system caddy && \
    useradd --system --gid caddy --create-home --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy && \
    usermod -aG www-data caddy && \
    apt-get purge -y --auto-remove wget && \
    apt-get clean && \
    rm -rf caddy_*.tar.gz /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -p /run/php /i-librarian /etc/caddy /var/log/caddy

COPY app /i-librarian/app/
COPY bin /i-librarian/bin/
COPY classes /i-librarian/classes/
COPY config /i-librarian/config/
COPY data /i-librarian/data/
COPY public /i-librarian/public/
COPY Caddyfile /etc/caddy/Caddyfile
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# www-data UID is 33.
RUN chown -R www-data:www-data /i-librarian/data/
RUN chown -R caddy:caddy /var/log/caddy

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]

STOPSIGNAL SIGQUIT