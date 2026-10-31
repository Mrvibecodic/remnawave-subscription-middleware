package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
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
	Retry   bool              `json:"rt,omitempty"`
	// Панель из сети Go не открывается — список соберёт PHP; это настройка сети, а не сбой Go.
	PanelDown string `json:"panel_down,omitempty"`
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

// Ключ пользователя для поиска повторов: uuid, без него — shortUuid.
type userKey struct {
	UUID  string `json:"uuid"`
	Short string `json:"shortUuid"`
}

// Список сдвинулся, пока шли страницы: пользователя создали или удалили посреди обхода.
var errDrift = errors.New("список менялся во время обхода")

type usersPart struct {
	users []json.RawMessage
	total int64
}

// Один обход: первая страница, по её total — остальные параллельно. Отдаёт сырые страницы по порядку.
func (p *panel) usersOnce(ctx context.Context) ([][]json.RawMessage, int64, error) {
	first, total, err := p.usersPage(ctx, 0)
	if err != nil {
		return nil, 0, err
	}
	pages := 1
	if total > int64(len(first)) && len(first) > 0 {
		pages = int((total + usersPage - 1) / usersPage)
	}
	if pages > usersMaxPages {
		return nil, 0, errors.New("слишком много страниц пользователей")
	}

	parts := make([]usersPart, pages)
	parts[0] = usersPart{first, total}
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
			users, t, err := p.usersPage(ctx, i*usersPage)
			mu.Lock()
			defer mu.Unlock()
			if err != nil {
				if firstEr == nil {
					firstEr = err
				}
				return
			}
			parts[i] = usersPart{users, t}
		}(i)
	}
	wg.Wait()
	if firstEr != nil {
		return nil, 0, firstEr
	}

	// Полнота: total один на всех страницах и собрано ровно столько, без повторов.
	out := make([][]json.RawMessage, pages)
	n := int64(0)
	seen := make(map[string]struct{}, min(total, 1<<20))
	for i, part := range parts {
		if part.total != total {
			return nil, 0, errDrift
		}
		for _, u := range part.users {
			var k userKey
			if json.Unmarshal(u, &k) != nil {
				return nil, 0, errors.New("пользователь не разобрался")
			}
			key := k.UUID
			if key == "" {
				key = "s:" + k.Short
			}
			if key != "s:" {
				if _, dup := seen[key]; dup {
					return nil, 0, errDrift
				}
				seen[key] = struct{}{}
			}
		}
		n += int64(len(part.users))
		out[i] = part.users
	}
	if n != total {
		return nil, 0, errDrift
	}
	return out, total, nil
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

	pages, total, err := p.usersOnce(ctx)
	// Список сдвинулся — ещё один обход целиком, если на него осталось время: какие именно
	// пользователи выпали, по сдвинутым страницам не понять.
	if errors.Is(err, errDrift) && time.Since(t0) < time.Until(deadlineOf(ctx)) {
		out.Retry = true
		pages, total, err = p.usersOnce(ctx)
	}
	out.Hits = p.hits
	if err != nil {
		var ue *url.Error
		switch {
		case errors.Is(err, context.DeadlineExceeded) || ctx.Err() != nil:
			err = fmt.Errorf("не уложился в %d с", int(budget.Seconds()))
		case errors.As(err, &ue) && len(p.hits) > 0 && p.hits[0].C == 0 && !out.Retry:
			// Первая же страница не дошла по сети: Go не видит панель из своей сети.
			out.PanelDown = ue.Err.Error()
			return out, nil
		}
		return out, err
	}

	out.Users = make([]json.RawMessage, 0, total)
	for _, part := range pages {
		for _, u := range part {
			pu, err := project(u, in.Fields)
			if err != nil {
				return out, err
			}
			out.Users = append(out.Users, pu)
		}
	}
	out.Pages = len(pages)
	out.Total = total
	out.Ms = time.Since(t0).Milliseconds()
	return out, nil
}

func deadlineOf(ctx context.Context) time.Time {
	dl, ok := ctx.Deadline()
	if !ok {
		return time.Now().Add(time.Hour)
	}
	return dl
}
