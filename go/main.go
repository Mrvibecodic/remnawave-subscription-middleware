package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

// Меняется, только когда меняется формат запросов прослойки к сервису.
const bridgeVersion = 1

// Что умеет этот Go: прослойка просит у него только перечисленное, остальное делает сама.
var caps = []string{"sessions", "users"}

var (
	version = "dev"
	commit  = ""
)

func listenAddr() string {
	if a := strings.TrimSpace(os.Getenv("SUBMW_GO_LISTEN")); a != "" {
		return a
	}
	return ":8090"
}

func reply(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	if err := encodeJSON(w, v); err != nil {
		slog.Warn("ответ не отправлен", "err", err)
	}
}

func authed(secret string, next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if secret == "" {
			reply(w, http.StatusServiceUnavailable, map[string]any{"ok": false, "err": "nosecret"})
			return
		}
		got, _ := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")
		if subtle.ConstantTimeCompare([]byte(got), []byte(secret)) != 1 {
			reply(w, http.StatusUnauthorized, map[string]any{"ok": false, "err": "secret"})
			return
		}
		next(w, r)
	}
}

func handler(secret string) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = w.Write([]byte("ok\n"))
	})
	mux.HandleFunc("GET /v1/hello", authed(secret, func(w http.ResponseWriter, _ *http.Request) {
		reply(w, http.StatusOK, map[string]any{"ok": true, "v": version, "commit": commit, "bridge": bridgeVersion, "caps": caps})
	}))
	mux.HandleFunc("POST /v1/sessions/poll", authed(secret, func(w http.ResponseWriter, r *http.Request) {
		var in pollReq
		dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20))
		if err := dec.Decode(&in); err != nil {
			reply(w, http.StatusBadRequest, map[string]any{"ok": false, "err": "запрос не разобрался"})
			return
		}
		out, err := poll(r.Context(), in)
		if err != nil {
			reply(w, http.StatusBadRequest, map[string]any{"ok": false, "err": err.Error(), "v": version, "bridge": bridgeVersion})
			return
		}
		if out.PanelDown != "" {
			slog.Warn("панель недоступна", "err", out.PanelDown)
		}
		reply(w, http.StatusOK, out)
	}))
	mux.HandleFunc("POST /v1/users/list", authed(secret, func(w http.ResponseWriter, r *http.Request) {
		var in usersReq
		dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20))
		if err := dec.Decode(&in); err != nil {
			reply(w, http.StatusBadRequest, map[string]any{"ok": false, "err": "запрос не разобрался"})
			return
		}
		out, err := listUsers(r.Context(), in)
		if out.PanelDown != "" {
			slog.Warn("панель недоступна", "err", out.PanelDown)
		}
		if err != nil {
			reply(w, http.StatusBadGateway, map[string]any{"ok": false, "err": err.Error(), "v": version, "bridge": bridgeVersion, "hits": out.Hits})
			return
		}
		reply(w, http.StatusOK, out)
	}))
	return mux
}

func health() int {
	addr := listenAddr()
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return 1
	}
	if host == "" || host == "0.0.0.0" || host == "::" {
		host = "127.0.0.1"
	}
	c := &http.Client{Timeout: 3 * time.Second}
	resp, err := c.Get("http://" + net.JoinHostPort(host, port) + "/healthz")
	if err != nil {
		return 1
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 1
	}
	return 0
}

func run() error {
	secret := strings.TrimSpace(os.Getenv("SUBMW_GO_SECRET"))
	if secret == "" {
		slog.Warn("SUBMW_GO_SECRET не задан — запросы прослойки отклоняются; секрет показан в админке: «О системе» → «Go-сервис»")
	}
	srv := &http.Server{
		Addr:              listenAddr(),
		Handler:           handler(secret),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      150 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	errc := make(chan error, 1)
	go func() { errc <- srv.ListenAndServe() }()
	slog.Info("submw-go запущен", "listen", srv.Addr, "version", version, "bridge", bridgeVersion)

	select {
	case err := <-errc:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return fmt.Errorf("сервер: %w", err)
	case <-ctx.Done():
	}
	sctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := srv.Shutdown(sctx); err != nil {
		return fmt.Errorf("остановка: %w", err)
	}
	return nil
}

func main() {
	slog.SetDefault(slog.New(slog.NewTextHandler(os.Stdout, nil)))
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "health":
			os.Exit(health())
		case "version":
			fmt.Println(version, commit)
			return
		}
	}
	if err := run(); err != nil {
		slog.Error("завершение с ошибкой", "err", err)
		os.Exit(1)
	}
}
