package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
)

func usersMock(n int, failStart int, shape string, inflight *int32, peak *int32) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/users" {
			w.WriteHeader(404)
			return
		}
		cur := atomic.AddInt32(inflight, 1)
		defer atomic.AddInt32(inflight, -1)
		for {
			pk := atomic.LoadInt32(peak)
			if cur <= pk || atomic.CompareAndSwapInt32(peak, pk, cur) {
				break
			}
		}
		start, _ := strconv.Atoi(r.URL.Query().Get("start"))
		size, _ := strconv.Atoi(r.URL.Query().Get("size"))
		if start == failStart {
			w.WriteHeader(502)
			return
		}
		if shape == "bad" {
			_, _ = w.Write([]byte(`{"response":[]}`))
			return
		}
		var us []string
		for i := start; i < n && i < start+size; i++ {
			us = append(us, fmt.Sprintf(`{"id":%d,"uuid":"u-%d","shortUuid":"s%d","username":"user%d","status":"ACTIVE","hwidDeviceLimit":null,"userTraffic":{"usedTrafficBytes":%d}}`, i+1, i, i, i, i*10))
		}
		_, _ = fmt.Fprintf(w, `{"response":{"users":[%s],"total":%d}}`, strings.Join(us, ","), n)
	}))
}

func ureq(base string, fields ...string) usersReq {
	return usersReq{Panel: panelConf{URL: base, Token: "t", TLS: true}, Fields: fields, BudgetMs: 10000}
}

func TestUsersAllPagesInOrder(t *testing.T) {
	var in, pk int32
	srv := usersMock(1003, -1, "", &in, &pk)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL))
	if err != nil {
		t.Fatal(err)
	}
	if out.Total != 1003 || len(out.Users) != 1003 || out.Pages != 5 {
		t.Fatalf("total=%d users=%d pages=%d", out.Total, len(out.Users), out.Pages)
	}
	for i, u := range out.Users {
		var x map[string]any
		_ = json.Unmarshal(u, &x)
		if x["shortUuid"] != fmt.Sprintf("s%d", i) {
			t.Fatalf("порядок нарушен на %d: %v", i, x["shortUuid"])
		}
	}
	if !strings.Contains(string(out.Users[7]), `"userTraffic":{"usedTrafficBytes":70}`) {
		t.Errorf("полный объект изменился: %s", out.Users[7])
	}
	if pk > usersParallel {
		t.Errorf("одновременно %d запросов, предел %d", pk, usersParallel)
	}
	if len(out.Hits) != 5 {
		t.Errorf("запросов учтено %d", len(out.Hits))
	}
}

func TestUsersFields(t *testing.T) {
	var in, pk int32
	srv := usersMock(3, -1, "", &in, &pk)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL, "shortUuid", "hwidDeviceLimit", "nope"))
	if err != nil {
		t.Fatal(err)
	}
	if string(out.Users[1]) != `{"hwidDeviceLimit":null,"shortUuid":"s1"}` {
		t.Errorf("поля: %s", out.Users[1])
	}
}

func TestUsersAllOrNothing(t *testing.T) {
	var in, pk int32
	srv := usersMock(1003, 750, "", &in, &pk)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL))
	if err == nil || out.Users != nil {
		t.Fatalf("сбой страницы — должен быть отказ без списка, получено %d", len(out.Users))
	}
	srv2 := usersMock(10, -1, "bad", &in, &pk)
	defer srv2.Close()
	if _, err := listUsers(context.Background(), ureq(srv2.URL)); err == nil {
		t.Error("неожиданный ответ панели принят")
	}
}

func TestUsersEmpty(t *testing.T) {
	var in, pk int32
	srv := usersMock(0, -1, "", &in, &pk)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL))
	if err != nil || out.Total != 0 || len(out.Users) != 0 || out.Pages != 1 {
		t.Fatalf("пустая панель: %v %+v", err, out)
	}
	b, _ := json.Marshal(out)
	if !strings.Contains(string(b), `"users":[]`) {
		t.Errorf("пустой список должен уходить массивом: %s", b)
	}
}

// Пользователя удалили посреди обхода: страницы сдвинулись. drifts — сколько первых обходов сдвинуты.
func driftMock(n int, drifts int32, passes *int32) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start, _ := strconv.Atoi(r.URL.Query().Get("start"))
		pass := atomic.LoadInt32(passes)
		if start == 0 {
			pass = atomic.AddInt32(passes, 1)
		}
		ids := make([]int, 0, n)
		for i := 0; i < n; i++ {
			ids = append(ids, i)
		}
		total := n
		if pass <= drifts && start > 0 {
			ids = append(ids[:10], ids[11:]...)
			total = n - 1
		}
		var us []string
		for i := start; i < len(ids) && i < start+usersPage; i++ {
			us = append(us, fmt.Sprintf(`{"uuid":"u-%d","shortUuid":"s%d"}`, ids[i], ids[i]))
		}
		_, _ = fmt.Fprintf(w, `{"response":{"users":[%s],"total":%d}}`, strings.Join(us, ","), total)
	}))
}

func TestUsersDriftRetried(t *testing.T) {
	var passes int32
	srv := driftMock(600, 1, &passes)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL, "shortUuid"))
	if err != nil {
		t.Fatal(err)
	}
	if !out.Retry || passes != 2 || len(out.Users) != 600 || out.Total != 600 {
		t.Fatalf("retry=%v passes=%d users=%d", out.Retry, passes, len(out.Users))
	}
	if string(out.Users[250]) != `{"shortUuid":"s250"}` {
		t.Errorf("после повтора список не тот: %s", out.Users[250])
	}
	if len(out.Hits) != 6 {
		t.Errorf("запросов учтено %d, ожидалось 6", len(out.Hits))
	}
}

func TestUsersDriftTwiceRefused(t *testing.T) {
	var passes int32
	srv := driftMock(600, 2, &passes)
	defer srv.Close()
	out, err := listUsers(context.Background(), ureq(srv.URL))
	if err == nil || out.Users != nil || passes != 2 {
		t.Fatalf("сдвиг дважды — должен быть отказ: err=%v users=%d passes=%d", err, len(out.Users), passes)
	}
	if err.Error() != "список менялся во время обхода" {
		t.Errorf("текст: %v", err)
	}
}

func TestUsersBogusTotal(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"response":{"users":[],"total":999999999999}}`))
	}))
	defer srv.Close()
	if _, err := listUsers(context.Background(), ureq(srv.URL)); err == nil {
		t.Fatal("пустая страница при огромном total принята")
	}
}

func TestUsersDuplicate(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"response":{"users":[{"uuid":"a","shortUuid":"x"},{"uuid":"a","shortUuid":"x"}],"total":2}}`))
	}))
	defer srv.Close()
	if _, err := listUsers(context.Background(), ureq(srv.URL)); err == nil {
		t.Fatal("повтор пользователя принят")
	}
}

func TestUsersPanelDown(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	base := srv.URL
	srv.Close()
	out, err := listUsers(context.Background(), ureq(base))
	if err != nil || out.PanelDown == "" || out.Users != nil {
		t.Fatalf("панель недоступна из сети Go: err=%v down=%q", err, out.PanelDown)
	}
}

func TestUsersBudgetText(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-r.Context().Done()
	}))
	defer srv.Close()
	in := ureq(srv.URL)
	in.BudgetMs = 1000
	_, err := listUsers(context.Background(), in)
	if err == nil || err.Error() != "не уложился в 1 с" {
		t.Fatalf("текст таймаута: %v", err)
	}
}
