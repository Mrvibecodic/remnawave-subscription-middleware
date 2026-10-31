package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sync"
	"time"
)

const (
	usersPage     = 250
	usersParallel = 4
	usersMaxPages = 4000
)

type usersReq struct {
	Panel    panelConf `json:"panel"`
	Fields   []string  `json:"fields"`
	BudgetMs int       `json:"budget_ms"`
}

type usersResp struct {
	OK      bool              `json:"ok"`
	Version string            `json:"v"`
	Commit  string            `json:"commit"`
	Bridge  int               `json:"bridge"`
	Ms      int64             `json:"ms"`
	Pages   int               `json:"pages"`
	Total   int64             `json:"total"`
	Users   []json.RawMessage `json:"users"`
	Hits    []hit             `json:"hits"`
}

type usersPageBody struct {
	Response *struct {
		Users []json.RawMessage `json:"users"`
		Total *json.Number      `json:"total"`
	} `json:"response"`
}

// Страница — только целиком и в ожидаемом виде: неполный список хуже, чем его отсутствие.
func (p *panel) usersPage(ctx context.Context, start int) ([]json.RawMessage, int64, error) {
	path := fmt.Sprintf("/api/users?size=%d&start=%d", usersPage, start)
	code, body, err := p.raw(ctx, http.MethodGet, path)
	if err != nil {
		return nil, 0, fmt.Errorf("страница с %d: %w", start, err)
	}
	if !ok2xx(code) {
		return nil, 0, fmt.Errorf("страница с %d: HTTP %d", start, code)
	}
	var pg usersPageBody
	dec := json.NewDecoder(bytes.NewReader(body))
	dec.UseNumber()
	if err := dec.Decode(&pg); err != nil || pg.Response == nil || pg.Response.Users == nil || pg.Response.Total == nil {
		return nil, 0, fmt.Errorf("страница с %d: неожиданный ответ панели", start)
	}
	total, err := pg.Response.Total.Int64()
	if err != nil || total < 0 {
		return nil, 0, fmt.Errorf("страница с %d: неожиданный total", start)
	}
	return pg.Response.Users, total, nil
}

func project(u json.RawMessage, fields []string) (json.RawMessage, error) {
	if len(fields) == 0 {
		return u, nil
	}
	var all map[string]json.RawMessage
	if err := json.Unmarshal(u, &all); err != nil {
		return nil, fmt.Errorf("пользователь не разобрался: %w", err)
	}
	out := make(map[string]json.RawMessage, len(fields))
	for _, f := range fields {
		if v, ok := all[f]; ok {
			out[f] = v
		}
	}
	b, err := json.Marshal(out)
	if err != nil {
		return nil, fmt.Errorf("пользователь не собрался: %w", err)
	}
	return b, nil
}

func listUsers(ctx context.Context, in usersReq) (usersResp, error) {
	out := usersResp{OK: true, Version: version, Commit: commit, Bridge: bridgeVersion}
	p, err := newPanel(in.Panel)
	if err != nil {
		return out, err
	}
	defer p.client.CloseIdleConnections()
	budget := time.Duration(max(1000, min(120000, in.BudgetMs))) * time.Millisecond
	ctx, cancel := context.WithTimeout(ctx, budget)
	defer cancel()
	t0 := time.Now()

	first, total, err := p.usersPage(ctx, 0)
	if err != nil {
		out.Hits = p.hits
		return out, err
	}
	pages := 1
	if total > int64(len(first)) && len(first) > 0 {
		pages = int((total + usersPage - 1) / usersPage)
	}
	if pages > usersMaxPages {
		out.Hits = p.hits
		return out, errors.New("слишком много страниц пользователей")
	}

	parts := make([][]json.RawMessage, pages)
	parts[0] = first
	var (
		mu      sync.Mutex
		wg      sync.WaitGroup
		firstEr error
	)
	gate := make(chan struct{}, usersParallel)
	for i := 1; i < pages; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			select {
			case gate <- struct{}{}:
			case <-ctx.Done():
				mu.Lock()
				if firstEr == nil {
					firstEr = ctx.Err()
				}
				mu.Unlock()
				return
			}
			defer func() { <-gate }()
			users, _, err := p.usersPage(ctx, i*usersPage)
			mu.Lock()
			defer mu.Unlock()
			if err != nil {
				if firstEr == nil {
					firstEr = err
				}
				return
			}
			parts[i] = users
		}(i)
	}
	wg.Wait()
	out.Hits = p.hits
	if firstEr != nil {
		return out, firstEr
	}

	out.Users = make([]json.RawMessage, 0, total)
	for _, part := range parts {
		for _, u := range part {
			pu, err := project(u, in.Fields)
			if err != nil {
				return out, err
			}
			out.Users = append(out.Users, pu)
		}
	}
	out.Pages = pages
	out.Total = total
	out.Ms = time.Since(t0).Milliseconds()
	return out, nil
}
