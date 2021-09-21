# Routing
- Jika ada lebih dari satu endpoint, kita membutuhkan routing
- File main.go akan diubah agar parameter server, yaitu http.Server.Handler akan diarahkan ke file routing yang mengimplementasikan interface http.Handler

```
    // parameter server
	server := http.Server{
		Addr:         os.Getenv("APP_PORT"),
		Handler:      routing.API(db, log),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}
```

- Berikut full kode main.go mengikuti perubahan parameter Handler

```
package main

import (
	"context"
	"essentials/libraries/config"
	"essentials/libraries/database"
	"essentials/routing"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	if _, ok := os.LookupEnv("APP_ENV"); !ok {
		config.Setup(".env")
	}

	// =========================================================================
	// Logging
	log := log.New(os.Stdout, "Essentials : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

	if err := run(log); err != nil {
		log.Printf("error: shutting down: %s", err)
		os.Exit(1)
	}
}

func run(log *log.Logger) error {

	// =========================================================================
	// App Starting

	log.Printf("main : Started")
	defer log.Println("main : Completed")

	// =========================================================================

	// Start Database

	db, err := database.Open()
	if err != nil {
		return fmt.Errorf("connecting to db: %v", err)
	}
	defer db.Close()

	// parameter server
	server := http.Server{
		Addr:         os.Getenv("APP_PORT"),
		Handler:      routing.API(db, log),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrors := make(chan error, 1)
	// mulai listening server
	go func() {
		log.Println("server listening on", server.Addr)
		serverErrors <- server.ListenAndServe()
	}()

	// Membuat channel untuk mendengarkan sinyal interupsi/terminate dari OS.
	// Menggunakan channel buffered karena paket signal membutuhkannya.
	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, os.Interrupt, syscall.SIGTERM)

	// Mengontrol penerimaan data dari channel,
	// jika ada error saat listenAndServe server maupun ada sinyal shutdown yang diterima
	select {
	case err := <-serverErrors:
		return fmt.Errorf("Starting server: %v", err)

	case <-shutdown:
		log.Println("caught signal, shutting down")

		// Jika ada shutdown, meminta tambahan waktu 5 detik untuk menyelesaikan proses yang sedang berjalan.
		const timeout = 5 * time.Second
		ctx, cancel := context.WithTimeout(context.Background(), timeout)
		defer cancel()

		if err := server.Shutdown(ctx); err != nil {
			log.Printf("main : Graceful shutdown did not complete in %v : %v", timeout, err)
			if err := server.Close(); err != nil {
				return fmt.Errorf("could not stop server gracefully: %v", err)
			}
		}
	}

	return nil
}

``` 

- Kode di atas error karena kita belum mebuat file routing/route.go yang menghandle routing

## Routing Menggunakan ServeMux
- Golang sudah mempunyai routing bawaan yaitu http.ServeMux
- Kita akan buat routing yang mengimplementasikan interface http.Handler. Buat file routing/route.go yang berisi :

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"log"
	"net/http"
)

type app struct {
	mux *http.ServeMux
}

// API : implement a http.Handler interface
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := new(app)
	app.mux = http.NewServeMux()

	users := controllers.Users{Db: db, Log: log}

	app.mux.HandleFunc("/users", users.List)

	return app
}

```

- Kode di atas jika dijalankan akan error `*app does not implement http.Handler (missing ServeHTTP method)` karena func API return-nya adalah interface http.Handler namun nyatanya yang direturn adalah *app
- Interface http.Handler mempunyai method abstract bernama ServeHTTP
- Karena itu, *app harus mengimplementasikan interface http.Handler dengan membuat method konkret ServeHTTP

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"log"
	"net/http"
)

type app struct {
	mux *http.ServeMux
}

// API : implement a http.Handler interface
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := new(app)
	app.mux = http.NewServeMux()

	users := controllers.Users{Db: db, Log: log}

	app.mux.HandleFunc("/users", users.List)

	return app
}

// ServeHTTP implements the http.Handler interface.
func (a *app) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

```

## Penggunaan HTTP Method dalam ServeMux 
- HandleFunc tidak memperhatikan http method, sehingga baik method GET, POST, PUT, DELETE akan mengeksekusi handler users.List
- Untuk mendukung method kita perlu merubah fungsi HandleFunc di atas.

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"log"
	"net/http"
)

type app struct {
	mux *http.ServeMux
}

// ServeHTTP implements the http.Handler interface.
func (a *app) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

// API : implement a http.Handler interface
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := new(app)
	app.mux = http.NewServeMux()

	users := controllers.Users{Db: db, Log: log}

	app.mux.HandleFunc("/users", func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodGet:
			users.List(w, r)
		case http.MethodPost:
			users.Create(w, r)
		default:
			http.NotFound(w, r)
		}
	})

	app.mux.HandleFunc("/users/detail", func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodGet:
			users.View(w, r)
		case http.MethodPut:
			users.Update(w, r)
		case http.MethodDelete:
			users.Delete(w, r)
		default:
			http.NotFound(w, r)
		}
	})

	return app
}

```

- Tambahkan method lainnya di file controllers/users.go

```
package controllers

import (
	"database/sql"
	"encoding/json"
	"essentials/models"
	"essentials/payloads/response"
	"fmt"
	"log"
	"net/http"
)

// Users : struct for set Users Dependency Injection
type Users struct {
	Db  *sql.DB
	Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
	user := new(models.User)
	list, err := user.List(u.Db)
	if err != nil {
		u.Log.Println("error get list user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	var responseList []response.UserResponse
	for _, l := range list {
		var res response.UserResponse
		res.Transform(l)
		responseList = append(responseList, res)
	}

	data, err := json.Marshal(responseList)
	if err != nil {
		u.Log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User telah dibuat"
		}
	`
	if _, err := w.Write([]byte(json)); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
	var id string
	if keys, ok := r.URL.Query()["id"]; ok {
		id = string(keys[0])
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "Lihat User %s"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
	var id string
	if keys, ok := r.URL.Query()["id"]; ok {
		id = string(keys[0])
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah diupdate"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
	var id string
	if keys, ok := r.URL.Query()["id"]; ok {
		id = string(keys[0])
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah dihapus"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

``` 

## Routing dengan httprouter
- http.ServeMux sangat handal performance-nya. Namun http.ServeMux tidak support pattern dalam routing url, sehingga terkesan tidak modern. Padahal umumnya sekarang untuk routing ResT kita menggunakan pattern, seperti :

```
GET /users/:id
PUT /users/:id
DELETE /users/:id
```

- Karena itulah terpaksa kita harus membuat routing sendiri atau memilih menggunakan library routing lain yang sudah ada. Salah satunya adalah [httprouter](https://github.com/julienschmidt/httprouter)
- Performance httprouter sangat handal -- [lihat benchmark](https://github.com/julienschmidt/go-http-routing-benchmark) --
- Kekurangan httprouter adalah tidak mendukung standard http.Handler dan tidak ada middleware
- Tapi kita bisa membuat middleware sendiri dan mengubah sedikit agar httprouter mendukung standar http.Handler.
- Ubah file routing/route.go menjadi :

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

type app struct {
	mux *httprouter.Router
}

// ServeHTTP implements the http.Handler interface.
func (a *app) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

// API : implement a http.Handler interface
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := new(app)
	app.mux = httprouter.New()

	users := controllers.Users{Db: db, Log: log}

	app.mux.Handle(http.MethodGet, "/users", users.List)
	app.mux.Handle(http.MethodPost, "/users", users.Create)
	app.mux.Handle(http.MethodGet, "/users/:id", users.View)
	app.mux.Handle(http.MethodPut, "/users/:id", users.Update)
	app.mux.Handle(http.MethodDelete, "/users/:id", users.Delete)

	return app
}
```

- Ubah file controllers/users.go menjadi :

```
package controllers

import (
	"database/sql"
	"encoding/json"
	"essentials/models"
	"essentials/payloads/response"
	"fmt"
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

// Users : struct for set Users Dependency Injection
type Users struct {
	Db  *sql.DB
	Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request, _ httprouter.Params) {
	user := new(models.User)
	list, err := user.List(u.Db)
	if err != nil {
		u.Log.Println("error get list user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	var responseList []response.UserResponse
	for _, l := range list {
		var res response.UserResponse
		res.Transform(l)
		responseList = append(responseList, res)
	}

	data, err := json.Marshal(responseList)
	if err != nil {
		u.Log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request, _ httprouter.Params) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User telah dibuat"
		}
	`
	if _, err := w.Write([]byte(json)); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
	id := ps.ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "Lihat User %s"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
	id := ps.ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah diupdate"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
	id := ps.ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah dihapus"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}
```

- Routing ini sudah berjalan dengan baik. Namun agar kode routing lebih dimaintenance, file routing/route.go akan dipecah menjadi dua file. Kode-kode yang mengatur tentang app akan dijadikan library tersendiri.
- Ubah file routing/route.go menjadi :

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"essentials/libraries/api"
	"log"
	"net/http"
)

// API : implement a http.Handler interface
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := api.NewApp(log)

	users := controllers.Users{Db: db, Log: log}

	app.Handle(http.MethodGet, "/users", users.List)
	app.Handle(http.MethodPost, "/users", users.Create)
	app.Handle(http.MethodGet, "/users/:id", users.View)
	app.Handle(http.MethodPut, "/users/:id", users.Update)
	app.Handle(http.MethodDelete, "/users/:id", users.Delete)

	return app
}
```

- Buat file libraries/api/app.go yang berisi :

```
package api

import (
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

// App is the entrypoint into our application and what controls the context of
// each request. Feel free to add any configuration data/logic on this type.
type App struct {
	log *log.Logger
	mux *httprouter.Router
}

// Handle associates a httprouter Handle function with an HTTP Method and URL pattern.
func (a *App) Handle(method, url string, h httprouter.Handle) {
	a.mux.Handle(method, url, h)
}

// ServeHTTP implements the http.Handler interface.
func (a *App) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

//NewApp is function to create new App
func NewApp(log *log.Logger) *App {
	return &App{
		log: log,
		mux: httprouter.New(),
	}
}
```

## Standard http.Handler
- httprouter tidak menggunakan standard http handle dengan 3 params, yaitu : http.ResponseWriter, *http.Request, dan httprouter.Params. Pada sub bab ini, kita akan mengubah httprouter.Handle menggunakan standard http.Handle
- Buat `type Handler func(http.ResponseWriter, *http.Request)` di file libraries/api/app.go
- Buat `type Ctx string` di file libraries/api/app.go untuk dijadikan key saat memindahkan httrouter.params ke context 
- Ubah parameter httprouter.Handle menjadi http.Handle di fungsi App.Handle di file libraries/api/app.go

```
// Handle associates a httprouter Handle function with an HTTP Method and URL pattern.
func (a *App) Handle(method, url string, h Handler) {

	fn := func(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
		ctx := context.WithValue(r.Context(), Ctx("ps"), ps)
		h(w, r.WithContext(ctx))
	}

	a.mux.Handle(method, url, fn)
}
```

- Keseluruhan file libraries/api/app.go setelah mengalami perubahan adalah sebagai berikut:

```
package api

import (
	"context"
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

// App is the entrypoint into our application and what controls the context of
// each request. Feel free to add any configuration data/logic on this type.
type App struct {
	log *log.Logger
	mux *httprouter.Router
}

// Handler type as standard http.Handle
type Handler func(http.ResponseWriter, *http.Request)

// Ctx type for encapsulated context key
type Ctx string

// Handle associates a httprouter Handle function with an HTTP Method and URL pattern.
func (a *App) Handle(method, url string, h Handler) {

	fn := func(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
		ctx := context.WithValue(r.Context(), Ctx("ps"), ps)
		h(w, r.WithContext(ctx))
	}

	a.mux.Handle(method, url, fn)
}

// ServeHTTP implements the http.Handler interface.
func (a *App) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mux.ServeHTTP(w, r)
}

//NewApp is function to create new App
func NewApp(log *log.Logger) *App {
	return &App{
		log: log,
		mux: httprouter.New(),
	}
}

```

- Ubah kembali file controllers/users.go agar sesuai dengan standar http.Handle

```
package controllers

import (
	"database/sql"
	"encoding/json"
	"essentials/libraries/api"
	"essentials/models"
	"essentials/payloads/response"
	"fmt"
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

// Users : struct for set Users Dependency Injection
type Users struct {
	Db  *sql.DB
	Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
	user := new(models.User)
	list, err := user.List(u.Db)
	if err != nil {
		u.Log.Println("error get list user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	var responseList []response.UserResponse
	for _, l := range list {
		var res response.UserResponse
		res.Transform(l)
		responseList = append(responseList, res)
	}

	data, err := json.Marshal(responseList)
	if err != nil {
		u.Log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User telah dibuat"
		}
	`
	if _, err := w.Write([]byte(json)); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
	id := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "Lihat User %s"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
	id := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah diupdate"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
	id := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	json := `
		{
			"message": "User %s telah dihapus"
		}
	`
	if _, err := w.Write([]byte(fmt.Sprintf(json, id))); err != nil {
		u.Log.Println("error writing result", err)
	}
}
```

## Handle CORS
- Untuk handle CORS tambahkan header seperti berikut di file libraries/api/app.go

```
package api

import (
	"context"
	"log"
	"net/http"

	"github.com/julienschmidt/httprouter"
)

// App struct for new api
type App struct {
	log *log.Logger
	mux *httprouter.Router
}

// Handler type as standard http.Handle
type Handler func(http.ResponseWriter, *http.Request)

// Ctx type for encapsulated context key
type Ctx string

// Handle associates a httprouter Handle function with an HTTP Method and URL pattern.
func (a *App) Handle(method, url string, h Handler) {

	fn := func(w http.ResponseWriter, r *http.Request, ps httprouter.Params) {
		ctx := context.WithValue(r.Context(), Ctx("ps"), ps)

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
func NewApp(log *log.Logger) *App {
	return &App{log: log, mux: httprouter.New()}
}

```

- File routing juga memanggil method HandleCors 

```
package routing

import (
	"database/sql"
	"essentials/controllers"
	"essentials/libraries/api"
	"log"
	"net/http"
)

// API handling routing
func API(db *sql.DB, log *log.Logger) http.Handler {
	app := api.NewApp(log)
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