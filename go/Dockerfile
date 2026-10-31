FROM golang:1.27.3-alpine3.24 AS build
ARG SUBMW_GO_VERSION=dev
ARG SUBMW_GO_COMMIT=
WORKDIR /src
COPY go.mod ./
COPY *.go ./
RUN CGO_ENABLED=0 go test ./... \
 && CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=${SUBMW_GO_VERSION} -X main.commit=${SUBMW_GO_COMMIT}" -o /out/submw-go .

FROM scratch
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=build /out/submw-go /submw-go
USER 65534:65534
EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 CMD ["/submw-go", "health"]
ENTRYPOINT ["/submw-go"]
