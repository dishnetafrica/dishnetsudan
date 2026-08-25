# Static site. No build step, no runtime, nothing to patch but nginx itself.
FROM nginx:1.27-alpine

RUN rm -f /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/dishnet.conf
COPY site/      /usr/share/nginx/html/

# Unprivileged port so the container needs no root.
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s \
  CMD wget -qO- http://127.0.0.1:8080/ >/dev/null 2>&1 || exit 1
