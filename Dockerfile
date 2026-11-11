FROM ubuntu:22.04

LABEL org.opencontainers.image.title="Offprintless" \
      org.opencontainers.image.description="Self-hosted reference manager for PDFs and bibliographic metadata, with project-based access. A fork of I, Librarian Free." \
      org.opencontainers.image.version="5.11.3" \
      org.opencontainers.image.licenses="GPL-3.0-only" \
      org.opencontainers.image.source="https://github.com/wenjie2wang/offprintless" \
      org.opencontainers.image.base.name="ubuntu:22.04"

ARG TIMEZONE="UTC"
ARG CADDY_VERSION="2.10.2"

RUN apt update && \
	DEBIAN_FRONTEND=noninteractive TZ=${TIMEZONE} apt install -qy \
    wget \
    tzdata \
    php-fpm \
    php-mbstring \
    php-sqlite3 \
    php-curl \
    php-gd \
    php-xml \
    php-json \
    php-zip \
    php-intl \
    php-ldap \
    ghostscript \
    poppler-utils \
    tesseract-ocr && \
	apt-get clean && \
	rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
	wget https://github.com/caddyserver/caddy/releases/download/v${CADDY_VERSION}/caddy_${CADDY_VERSION}_linux_amd64.tar.gz && \
	tar xzf caddy_${CADDY_VERSION}_linux_amd64.tar.gz caddy && \
	chown root:root caddy && \
	mv caddy /usr/bin/caddy && \
    groupadd --system caddy && \
    useradd --system --gid caddy --create-home --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy && \
    usermod -aG www-data caddy && \
    rm caddy_${CADDY_VERSION}_linux_amd64.tar.gz

RUN mkdir /i-librarian
RUN mkdir /run/php
RUN mkdir /etc/caddy
RUN mkdir /var/log/caddy

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