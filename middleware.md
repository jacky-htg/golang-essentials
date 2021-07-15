# Middleware
Pada bab ini kita akan membuat middleware. Kasus yang digunakan adalah handling auth.

- Buat file baru libraries/api/middleware.go
```
package api

// Middleware is a function designed to run some code before and/or after
// another Handler. It is designed to remove boilerplate or other concerns not
// direct to any given Handler.
type Middleware func(Handler) Handler

// wrapMiddleware creates a new handler by wrapping middleware around a final
// handler. The middlewares' Handlers will be executed by requests in the order
// they are provided.
func wrapMiddleware(mw []Middleware, handler Handler) Handler {

	// Loop backwards through the middleware invoking each one. Replace the
	// handler with the new wrapped handler. Looping backwards ensures that the
	// first middleware of the slice is the first to be executed by requests.
	for i := len(mw) - 1; i >= 0; i-- {
		h := mw[i]
		if h != nil {
			handler = h(handler)
		}
	}

	return handler
}
```
- list semua middleware yang diperlukan pada routing/route.go
```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"essentials/libraries/api"
    "essentials/middlewares"
	"log"
	"net/http"
)

func mid(db *sql.DB, log *log.Logger) []api.Middleware {
	var mw []api.Middleware
	mw = append(mw, middlewares.Auths(db, log, []string{"/login"}))

	return mw
}

// API handling routing
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := api.NewApp(log, mid(db, log)...)
	app.HandleCors()

	// Users routing
	{
		users := controllers.Users{Db: db, Log: log}
		app.Handle(http.MethodGet, "/users", users.List)
		app.Handle(http.MethodPost, "/users", users.Create)
		app.Handle(http.MethodGet, "/users/:id", users.View)
		app.Handle(http.MethodPut, "/users/:id", users.Update)
		app.Handle(http.MethodDelete, "/users/:id", users.Delete)
	}

	return app
}
```

- Tambahkan field middleware di type App libraries/api/app.go
```
package api

import (
	"context"
	"log"
	"net/http"
	"time"

	"github.com/julienschmidt/httprouter"
)

// App struct for new api
type App struct {
	log *log.Logger
	mux *httprouter.Router
	mw  []Middleware
}

// Handler type as standard http.Handle
type Handler func(http.ResponseWriter, *http.Request)

// Ctx type for encapsulated context key
type Ctx string

// Handle associates a httprouter Handle function with an HTTP Method and URL pattern.
func (a *App) Handle(method, url string, h Handler) {
	h = wrapMiddleware(a.mw, h)

	fn := func(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
		ctx := context.WithValue(r.Context(), Ctx("ps"), ps)
		//const timeout = 1 * time.Second
		//ctx2, cancel := context.WithTimeout(ctx, timeout)
		//defer cancel()

		header := w.Header()
		header.Add("Access-Control-Allow-Origin", "*")
		header.Add("Access-Control-Allow-Methods", "DELETE, POST, GET, OPTIONS, PUT")
		header.Add("Access-Control-Allow-Headers", "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Token")
		header.Add("Content-Type", "application/json; charset=utf-8")

		h(w, r.WithContext(ctx))
	}

	a.mux.Handle(method, url, fn)
}

// HandleCors and OPTIONS response
func (a *App) HandleCors() {
	a.mux.GlobalOPTIONS = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Access-Control-Request-Method") != "" {
			// Set CORS headers
			header := w.Header()
			header.Add("Access-Control-Allow-Origin", "*")
			header.Add("Access-Control-Allow-Methods", "DELETE, POST, GET, OPTIONS, PUT")
			header.Add("Access-Control-Allow-Headers", "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Token")
			header.Add("Content-Type", "application/json; charset=utf-8")
		}

		// Adjust status code to 204
		w.WriteHeader(http.StatusNoContent)
	})
}

// ServeHTTP implements the http.Handler interface
func (a *App) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

// NewApp for create new api
func NewApp(log *log.Logger, mw ...Middleware) *App {
	return &App{
		log: log,
		mux: httprouter.New(),
		mw:  mw,
	}
}

```

- Buat middleware untuk handling authorization ( middlewares/auth.go )
```
package middlewares

import (
	"database/sql"
	"errors"
	"log"
	"net/http"

	"essentials/libraries/api"
)

// Auths middleware
func Auths(db *sql.DB, log *log.Logger, allow []string) api.Middleware {
	fn := func(before api.Handler) api.Handler {
		h := func(w http.ResponseWriter, r *http.Request) {
			var isAuth bool

			// hardcode athorization for true.
			// upcoming chapter, this line will execute RBAC checking
			isAuth = true

			if !isAuth {
				api.ResponseError(w, api.ErrForbidden(errors.New("Forbidden"), ""))
			} else {
				before(w, r)
			}
		}

		return h
	}

	return fn
}

```