# *Offprintless* in Docker

## Prepare permanent storage directory

```bash
mkdir -p /var/www/offprintless/library/data
chown -R 33:33 /var/www/offprintless/library/data
```
User 33:33 is the user/group ID (UID/GID) that *Offprintless* runs as. In an unlikely case of a conflict, you can set
the directory permissions to 0777 instead.

## Prepare config file

```bash
mkdir -p /var/www/offprintless/library/config
cp config/ilibrarian-default.ini /var/www/offprintless/library/config/ilibrarian.ini
```

## Build an image

From a checkout of this repository:

```bash
docker build -t offprintless:5.11.3 -f Dockerfile.local .
```

(`Dockerfile.local` also builds on ARM, for example an Apple Silicon Mac. The plain `Dockerfile` is the original's
x86-64 version and can also be fed a release tarball as `docker build -t offprintless:5.11.3 - < package.tar.xz`.)

## Run container

```bash
docker run -d --name offprintless -p 127.0.0.1:9050:80 -v /var/www/offprintless/library/data:/i-librarian/data \
  -v /var/www/offprintless/library/config:/i-librarian/config offprintless:5.11.3
```

## Docker compose alternative

```YAML
services:
  offprintless:
    image: offprintless:5.11.3
    container_name: offprintless
    restart: always
    ports:
      - "127.0.0.1:9050:80"
    volumes:
      - type: bind
        source: /var/www/offprintless/library/data
        target: /i-librarian/data
      - type: bind
        source: /var/www/offprintless/library/config
        target: /i-librarian/config
        read_only: true
```

```bash
docker compose up -d
```

## Access

*Offprintless* now runs at 127.0.0.1:9050. You can use the local address directly, or via reverse proxy. For instance, using Caddy:

* access *Offprintless* at https://library.example.com. Here, `library` subdomain is just an example. Use whatever you want.
```Caddyfile
library.example.com {
    reverse_proxy 127.0.0.1:9050
}
```
* alternatively, to access *Offprintless* on a `library` URL path at https://example.com/library.  Here, `library` path is literal.

```Caddyfile
example.com {
    handle /library* {
        reverse_proxy 127.0.0.1:9050
    }
}
```
