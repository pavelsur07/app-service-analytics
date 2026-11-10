FROM golang:1.27.3-alpine3.23 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/ozon-ads-controller ./cmd/ozon-ads-controller

FROM alpine:3.23.3 AS runtime
RUN apk add --no-cache ca-certificates && addgroup -S ads && adduser -S -G ads ads
COPY --from=build /out/ozon-ads-controller /usr/local/bin/ozon-ads-controller
USER ads
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s CMD wget -qO- http://127.0.0.1:8080/health || exit 1
ENTRYPOINT ["ozon-ads-controller"]
