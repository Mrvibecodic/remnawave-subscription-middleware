package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

const (
	maxNodes       = 500
	maxParallel    = 16
	firstPause     = 300 * time.Millisecond
	maxPause       = 2 * time.Second
	panelReqLimit  = 20 * time.Second
	retryPause     = 500 * time.Millisecond
	retryRoom      = 2 * time.Second
	panelBodyLimit = 32 << 20
)

type panelConf struct {
	URL    string `json:"url"`
	Token  string `json:"token"`
	Cookie string `json:"cookie"`
	XKey   string `json:"xkey"`
	TLS    bool   `json:"tls"`
}

type pollReq struct {
	Panel    panelConf `json:"panel"`
	Start    string    `json:"start"`
	Result   string    `json:"result"`
	Nodes    []string  `json:"nodes"`
	BudgetMs int       `json:"budget_ms"`
}

type nodeOut struct {
	St     string          `json:"st"`
	Err    string          `json:"err,omitempty"`
	NF     bool            `json:"nf,omitempty"`
	RT     bool            `json:"rt,omitempty"`
	Result json.RawMessage `json:"result,omitempty"`
	net    bool
	again  bool
}

type hit struct {
	M  string  `json:"m"`
	P  string  `json:"p"`
	C  int     `json:"c"`
	Ms float64 `json:"ms"`
}

type pollResp struct {
	OK        bool               `json:"ok"`
	Version   string             `json:"v"`
	Commit    string             `json:"commit"`
	Bridge    int                `json:"bridge"`
	Ms        int64              `json:"ms"`
	Nodes     map[string]nodeOut `json:"nodes"`
	Hits      []hit              `json:"hits"`
	PanelDown string             `json:"panel_down,omitempty"`
}

type panel struct {
	base   string
	conf   panelConf
	client *http.Client
	sem    chan struct{}
	mu     sync.Mutex
	hits   []hit
}

func newPanel(c panelConf) (*panel, error) {
	u, err := url.Parse(strings.TrimRight(c.URL, "/"))
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, errors.New("некорректный адрес панели")
	}
	if c.Token == "" {
		return nil, errors.New("нет токена панели")
	}
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.MaxIdleConnsPerHost = maxParallel
	tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: !c.TLS} //nolint:gosec // выключает сам админ: самоподписанный сертификат панели
	return &panel{
		base:   strings.TrimRight(c.URL, "/"),
		conf:   c,
		client: &http.Client{Transport: tr, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }},
		sem:    make(chan struct{}, maxParallel),
	}, nil
}

// Заголовки — те же, что шлёт PHP-часть (remnawave_api_request): панель и её реверс не должны видеть разницы.
func (p *panel) do(ctx context.Context, method, path string) (int, map[string]any, error) {
	select {
	case p.sem <- struct{}{}:
	case <-ctx.Done():
		return 0, nil, ctx.Err()
	}
	defer func() { <-p.sem }()

	rctx, cancel := context.WithTimeout(ctx, panelReqLimit)
	defer cancel()
	req, err := http.NewRequestWithContext(rctx, method, p.base+"/"+strings.TrimLeft(path, "/"), nil)
	if err != nil {
		return 0, nil, fmt.Errorf("запрос к панели: %w", err)
	}
	h := req.Header
	h["User-Agent"] = []string{""}
	h.Set("Authorization", "Bearer "+p.conf.Token)
	h.Set("Accept", "application/json")
	if strings.HasPrefix(p.base, "http://") {
		h.Set("X-Forwarded-Proto", "https")
		h.Set("X-Forwarded-For", "127.0.0.1")
	}
	if p.conf.Cookie != "" {
		h.Set("Cookie", p.conf.Cookie)
	}
	if p.conf.XKey != "" {
		h.Set("X-Api-Key", p.conf.XKey)
	}

	t0 := time.Now()
	resp, err := p.client.Do(req)
	code := 0
	var data map[string]any
	if err == nil {
		code = resp.StatusCode
		body, rerr := io.ReadAll(io.LimitReader(resp.Body, panelBodyLimit))
		resp.Body.Close()
		if rerr != nil {
			err = fmt.Errorf("ответ панели: %w", rerr)
			code = 0
		} else {
			dec := json.NewDecoder(bytes.NewReader(body))
			dec.UseNumber()
			_ = dec.Decode(&data)
		}
	}
	p.mu.Lock()
	p.hits = append(p.hits, hit{M: method, P: path, C: code, Ms: float64(time.Since(t0).Microseconds()) / 1000})
	p.mu.Unlock()
	return code, data, err
}

func fill(tpl, id string) string {
	return strings.ReplaceAll(tpl, "{id}", url.PathEscape(id))
}

func ok2xx(code int) bool { return code >= 200 && code < 300 }

func respObj(data map[string]any) map[string]any {
	r, _ := data["response"].(map[string]any)
	return r
}

// Текст ошибки старта — как у sess_job_start: сообщение панели, иначе ошибка сети, иначе код.
// again — сбой, который имеет смысл повторить: сеть или 5xx; отказ 4xx (нода не найдена, нет прав) — нет.
func (p *panel) start(ctx context.Context, path string) (job string, errText string, notFound bool, netErr bool, again bool) {
	code, data, err := p.do(ctx, http.MethodPost, path)
	if err != nil {
		var ue *url.Error
		if errors.As(err, &ue) {
			err = ue.Err
		}
		return "", err.Error(), false, true, true
	}
	if !ok2xx(code) {
		msg, _ := data["message"].(string)
		if code == 404 && strings.HasPrefix(msg, "Cannot POST") {
			return "", "нет такого адреса API", true, false, false
		}
		if msg == "" {
			msg = fmt.Sprintf("HTTP %d", code)
		}
		return "", msg, false, false, code >= 500
	}
	if r := respObj(data); r != nil {
		if j, ok := r["jobId"].(string); ok && j != "" {
			return j, "", false, false, false
		}
		if j, ok := r["jobId"].(json.Number); ok {
			return j.String(), "", false, false, false
		}
	}
	return "", "панель не вернула jobId", false, false, true
}

func roomFor(ctx context.Context, d time.Duration) bool {
	dl, ok := ctx.Deadline()
	return !ok || time.Until(dl) >= d
}

func sleepCtx(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-t.C:
		return true
	case <-ctx.Done():
		return false
	}
}

// Ожидание результата — как у sess_jobs_wait: неудачный опрос не конец, isFailed — отказ,
// isCompleted без result — тоже отказ; пауза растёт с 0,3 до 2 с.
func (p *panel) wait(ctx context.Context, path string) nodeOut {
	pause := firstPause
	for {
		if !sleepCtx(ctx, pause) {
			return nodeOut{St: "timeout", Err: "не успела"}
		}
		code, data, err := p.do(ctx, http.MethodGet, path)
		if ctx.Err() != nil {
			return nodeOut{St: "timeout", Err: "не успела"}
		}
		if err == nil && ok2xx(code) {
			if r := respObj(data); r != nil {
				if f, _ := r["isFailed"].(bool); f {
					return nodeOut{St: "fail", Err: "задача на панели не выполнилась", again: true}
				}
				if c, _ := r["isCompleted"].(bool); c {
					res, isObj := r["result"].(map[string]any)
					if !isObj {
						return nodeOut{St: "fail", Err: "панель не вернула результат", again: true}
					}
					raw, merr := json.Marshal(res)
					if merr != nil {
						return nodeOut{St: "fail", Err: "результат не разобрался"}
					}
					return nodeOut{St: "ok", Result: raw}
				}
			}
		}
		pause = min(maxPause, time.Duration(float64(pause)*1.5))
	}
}

func poll(ctx context.Context, in pollReq) (pollResp, error) {
	out := pollResp{OK: true, Version: version, Commit: commit, Bridge: bridgeVersion, Nodes: map[string]nodeOut{}}
	if len(in.Nodes) > maxNodes {
		return out, fmt.Errorf("нод больше %d", maxNodes)
	}
	if !strings.Contains(in.Start, "{id}") || !strings.Contains(in.Result, "{id}") || !strings.HasPrefix(in.Start, "/api/") || !strings.HasPrefix(in.Result, "/api/") {
		return out, errors.New("некорректные адреса API")
	}
	p, err := newPanel(in.Panel)
	if err != nil {
		return out, err
	}
	defer p.client.CloseIdleConnections()
	budget := time.Duration(max(1000, min(60000, in.BudgetMs))) * time.Millisecond
	ctx, cancel := context.WithTimeout(ctx, budget)
	defer cancel()

	t0 := time.Now()
	var mu sync.Mutex
	var wg sync.WaitGroup
	ids := make([]string, 0, len(in.Nodes))
	for _, id := range in.Nodes {
		if _, dup := out.Nodes[id]; dup || id == "" {
			continue
		}
		out.Nodes[id] = nodeOut{St: "timeout", Err: "не успела"}
		ids = append(ids, id)
	}
	for _, id := range ids {
		wg.Add(1)
		go func(id string) {
			defer wg.Done()
			attempt := func() nodeOut {
				job, e, nf, ne, ag := p.start(ctx, fill(in.Start, id))
				switch {
				case ctx.Err() != nil:
					return nodeOut{St: "timeout", Err: "не успела"}
				case e != "":
					return nodeOut{St: "fail", Err: e, NF: nf, net: ne, again: ag}
				}
				return p.wait(ctx, fill(in.Result, job))
			}
			r := attempt()
			// Одна повторная попытка, только если после паузы останется время дождаться ответа.
			if r.again && roomFor(ctx, retryRoom+retryPause) && sleepCtx(ctx, retryPause) {
				r = attempt()
				r.RT = true
			}
			mu.Lock()
			out.Nodes[id] = r
			mu.Unlock()
		}(id)
	}
	wg.Wait()

	down, first := len(ids) > 0, ""
	for _, id := range ids {
		r := out.Nodes[id]
		if !r.net {
			down = false
		} else if first == "" {
			first = r.Err
		}
	}
	if down {
		out.PanelDown = first
	}
	out.Hits = p.hits
	out.Ms = time.Since(t0).Milliseconds()
	return out, nil
}

func encodeJSON(w io.Writer, v any) error {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return fmt.Errorf("ответ: %w", err)
	}
	_, err := w.Write(b.Bytes())
	return err
}
