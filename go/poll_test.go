package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

type mockPanel struct {
	mu      sync.Mutex
	headers []http.Header
	polls   map[string]int
	slow    map[string]bool
	failed  map[string]bool
	v2Only  bool
}

func (m *mockPanel) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	m.mu.Lock()
	m.headers = append(m.headers, r.Header.Clone())
	m.mu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	p := r.URL.Path
	if m.v2Only && strings.HasPrefix(p, "/api/connections/") {
		w.WriteHeader(404)
		_, _ = w.Write([]byte(`{"message":"Cannot POST ` + p + `","statusCode":404}`))
		return
	}
	switch {
	case r.Method == http.MethodPost && strings.HasPrefix(p, "/api/connections/by-node/"):
		id := strings.TrimPrefix(p, "/api/connections/by-node/")
		if id == "missing" {
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"message":"Node not found","errorCode":"A011"}`))
			return
		}
		w.WriteHeader(201)
		if id == "num" {
			_, _ = w.Write([]byte(`{"response":{"jobId":12345678901}}`))
			return
		}
		_, _ = w.Write([]byte(`{"response":{"jobId":"j-` + id + `"}}`))
	case r.Method == http.MethodGet && strings.HasPrefix(p, "/api/connections/by-node/"):
		job := strings.TrimPrefix(p, "/api/connections/by-node/")
		id := strings.TrimPrefix(job, "j-")
		m.mu.Lock()
		m.polls[id]++
		n := m.polls[id]
		m.mu.Unlock()
		switch {
		case m.failed[id]:
			_, _ = w.Write([]byte(`{"response":{"isCompleted":false,"isFailed":true,"result":null}}`))
		case m.slow[id] || n < 2:
			_, _ = w.Write([]byte(`{"response":{"isCompleted":false,"isFailed":false,"result":null}}`))
		default:
			_, _ = w.Write([]byte(`{"response":{"isCompleted":true,"isFailed":false,"result":{"nodeUuid":"` + id + `","users":[{"userId":12,"ips":[{"ip":"1.2.3.4","lastSeen":"x"}]},{"userId":"34","ips":[]}]}}}`))
		}
	default:
		w.WriteHeader(404)
	}
}

func newMock() *mockPanel {
	return &mockPanel{polls: map[string]int{}, slow: map[string]bool{}, failed: map[string]bool{}}
}

func req(base string, nodes ...string) pollReq {
	return pollReq{
		Panel:    panelConf{URL: base, Token: "tok", Cookie: "a=b", XKey: "xk", TLS: true},
		Start:    "/api/connections/by-node/{id}",
		Result:   "/api/connections/by-node/{id}",
		Nodes:    nodes,
		BudgetMs: 3000,
	}
}

func TestPollParallelAndHeaders(t *testing.T) {
	m := newMock()
	m.slow["s"] = true
	m.failed["f"] = true
	srv := httptest.NewServer(m)
	defer srv.Close()

	t0 := time.Now()
	out, err := poll(context.Background(), req(srv.URL, "a", "b", "s", "f", "missing", "num", "a"))
	if err != nil {
		t.Fatal(err)
	}
	if el := time.Since(t0); el < 2900*time.Millisecond || el > 4*time.Second {
		t.Fatalf("бюджет не соблюдён: %v", el)
	}
	cases := []struct {
		id, st, err string
	}{
		{"a", "ok", ""}, {"b", "ok", ""}, {"num", "ok", ""},
		{"s", "timeout", "не успела"},
		{"f", "fail", "задача на панели не выполнилась"},
		{"missing", "fail", "Node not found"},
	}
	for _, c := range cases {
		got := out.Nodes[c.id]
		if got.St != c.st || got.Err != c.err {
			t.Errorf("%s: %+v, ожидалось %s/%s", c.id, got, c.st, c.err)
		}
	}
	if len(out.Nodes) != 6 {
		t.Errorf("дубль ноды не схлопнулся: %d", len(out.Nodes))
	}
	var res map[string]any
	if err := json.Unmarshal(out.Nodes["a"].Result, &res); err != nil || len(res["users"].([]any)) != 2 {
		t.Errorf("результат ноды: %s", out.Nodes["a"].Result)
	}
	if !strings.Contains(string(out.Nodes["a"].Result), `"userId":12`) || !strings.Contains(string(out.Nodes["a"].Result), `"userId":"34"`) {
		t.Errorf("типы userId не сохранились: %s", out.Nodes["a"].Result)
	}
	if out.PanelDown != "" {
		t.Errorf("панель жива, а помечена недоступной: %s", out.PanelDown)
	}
	if len(out.Hits) == 0 {
		t.Error("нет учёта запросов")
	}
	m.mu.Lock()
	h := m.headers[0]
	m.mu.Unlock()
	if h.Get("Authorization") != "Bearer tok" || h.Get("X-Api-Key") != "xk" || h.Get("Cookie") != "a=b" || h.Get("Accept") != "application/json" {
		t.Errorf("заголовки: %v", h)
	}
	if h.Get("X-Forwarded-Proto") != "https" || h.Get("X-Forwarded-For") != "127.0.0.1" {
		t.Errorf("для http:// панели нужны x-forwarded-*: %v", h)
	}
	if ua, ok := h["User-Agent"]; ok {
		t.Errorf("User-Agent отправлен: %v", ua)
	}
}

func TestPollOldPanelFamily(t *testing.T) {
	m := newMock()
	m.v2Only = true
	srv := httptest.NewServer(m)
	defer srv.Close()
	out, err := poll(context.Background(), req(srv.URL, "a"))
	if err != nil {
		t.Fatal(err)
	}
	if r := out.Nodes["a"]; r.St != "fail" || !r.NF || r.Err != "нет такого адреса API" {
		t.Errorf("Cannot POST не распознан: %+v", r)
	}
}

func TestPollPanelDown(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	base := srv.URL
	srv.Close()
	out, err := poll(context.Background(), req(base, "a", "b"))
	if err != nil {
		t.Fatal(err)
	}
	if out.PanelDown == "" || out.Nodes["a"].St != "fail" {
		t.Errorf("недоступная панель не помечена: %+v", out)
	}
}

func TestPollValidation(t *testing.T) {
	bad := req("ftp://x", "a")
	if _, err := poll(context.Background(), bad); err == nil {
		t.Error("адрес не http(s) принят")
	}
	bad = req("http://x", "a")
	bad.Start = "/elsewhere/{id}"
	if _, err := poll(context.Background(), bad); err == nil {
		t.Error("адрес API вне /api/ принят")
	}
}

func TestAuth(t *testing.T) {
	for _, c := range []struct {
		secret, header string
		code           int
	}{
		{"", "Bearer x", 503},
		{"s3cret", "Bearer nope", 401},
		{"s3cret", "", 401},
		{"s3cret", "Bearer s3cret", 200},
	} {
		rr := httptest.NewRecorder()
		r := httptest.NewRequest(http.MethodGet, "/v1/hello", nil)
		if c.header != "" {
			r.Header.Set("Authorization", c.header)
		}
		handler(c.secret).ServeHTTP(rr, r)
		if rr.Code != c.code {
			t.Errorf("%q/%q: %d, ожидалось %d", c.secret, c.header, rr.Code, c.code)
		}
	}
	rr := httptest.NewRecorder()
	handler("").ServeHTTP(rr, httptest.NewRequest(http.MethodGet, "/healthz", nil))
	if rr.Code != 200 {
		t.Errorf("healthz без секрета: %d", rr.Code)
	}
}
