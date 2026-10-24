# syntax=docker/dockerfile:1
# multi-stage: сборка в golang, рантайм в alpine, non-root (services/go.md «Docker»).
# Контекст сборки — services/ozon-fetcher.
FROM golang:1.27-alpine AS build
WORKDIR /src
ENV CGO_ENABLED=0 GOFLAGS=-p=2
COPY go.mod go.sum ./
RUN go mod download
COPY cmd ./cmd
COPY internal ./internal
RUN go build -trimpath -ldflags="-s -w" -o /out/ozon-fetcher ./cmd/ozon-fetcher

FROM alpine:3.20
# wget (busybox) — для healthcheck /health; tzdata встроена в бинарник (time/tzdata).
RUN adduser -D -u 10001 app
COPY --from=build /out/ozon-fetcher /usr/local/bin/ozon-fetcher
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/health >/dev/null || exit 1
ENTRYPOINT ["ozon-fetcher"]
