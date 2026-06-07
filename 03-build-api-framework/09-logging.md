# Logging

* Ada dua issue log yang kita gunakan saat ini, yaitu menggunakan variabel global dan hanya support teks (bukan log terstruktur).
* Saat ini log menggunakan sebuah variabel di level paket. Kita akan mengubahnya menjadi variabel lokal yang kita suntikkan ke paket-paket yang membutuhkan dengan pattern dependency injection.
* log.Logger merupakan log berbasis teks yang ramah untuk dibaca mata manusia, namun tool monioring lebih mudah membaca log terstruktur, misalnya  dalam format json. 
* Kita akan mengganti penggunaan log.Logger dengan slog.Logger karena mempunyai kelebihan bisa digunakan dalam format teks maupun terstruktur. slog.Logger juga mempunyai fitur yang lebih kaya seperti fitur leveling log (Debug, Info, Warning, Error).
* Untuk kemudahan penggunaan slog.Logger, saya sudah membuat library untuk wrapping slog.Logger di [slog-library](https://github.com/jacky-htg/go-libs/blob/main/logger/logger.go)  
* Ubah file `cmd/cli/main.go` menjadi seperti berikut:

```go
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {
	log := logger.InitLogger(nil)
	if err := run(log); err != nil {
		log.Debug(context.Background(), "application error", slog.Any("error", err))
		os.Exit(1)
	}
}

func run(log logger.Logger) error {

	cfg, err := config.LoadConfig()
	if err != nil {
		return fmt.Errorf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %s", err)
	}
	defer db.Close()

	flag.Parse()

	if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
		if err := migration.Migrate(db, "migration"); err != nil {
			return fmt.Errorf("error: running migrations: %s", err)
		}
		log.Info(context.Background(), "migrations completed successfully")
	}

	return nil
}
```

* Log akan kita inisiasi di bootstrap, ubah file `internal/bootstrap/app.go` menjadi:

```go
package bootstrap

import (
	"database/sql"
	"fmt"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/logger"
	_ "github.com/lib/pq"
)

type App struct {
	Config   config.Config
	Database *sql.DB
	Log      logger.Logger

	Cleanup func()
}

func NewApp() (App, error) {
	cfg, err := config.LoadConfig()
	if err != nil {
		return App{}, fmt.Errorf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return App{}, fmt.Errorf("error: opening database: %s", err)
	}

	log := logger.InitLogger(nil)

	return App{
		Config:   cfg,
		Database: db,
		Log:      log,
		Cleanup: func() {
			if err := db.Close(); err != nil {
				fmt.Printf("error: closing database: %s\n", err)
			}
		},
	}, nil
}
```

* Semua pemakaian log akan menggunakan logger.Logger yang telah dibuat.
* Lewatkan logger.Logger ke service yang membutuhkan dengan pattern dependency injection
* Ubah file `cmd/server/main.go` menjadi:

```go
package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
	"workshop/internal/bootstrap"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
)

func main() {
	if err := run(); err != nil {
		fmt.Printf("error: running application: %s\n", err)
		os.Exit(1)
	}
}

func run() error {
	app, err := bootstrap.NewApp()
	if err != nil {
		return fmt.Errorf("error: initializing app: %s", err)
	}
	defer app.Cleanup()

	userRepository := repository.NewUserRepository(app.Database, app.Log)
	userService := service.NewUsers(userRepository, app.Log)
	userHandler := handler.NewUserHandler(userService, app.Log)

	// server
	server := &http.Server{
		Addr:         fmt.Sprintf("0.0.0.0:%d", app.Config.Server.AppPort),
		Handler:      http.HandlerFunc(userHandler.List),
		ReadTimeout:  app.Config.Server.ReadTimeout,
		WriteTimeout: app.Config.Server.WriteTimeout,
	}

	serverErrChan := make(chan error, 1)

	// start server in a goroutine
	go func() {
		app.Log.Info(context.Background(), "starting server", slog.String("addr", server.Addr))
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			serverErrChan <- fmt.Errorf("error: listening and serving: %s", err)
		}
		close(serverErrChan)
	}()

	shutdownChan := make(chan os.Signal, 1)
	signal.Notify(shutdownChan, os.Interrupt, syscall.SIGTERM)

	select {
	case err, ok := <-serverErrChan:
		if ok && err != nil {
			app.Log.Error(context.Background(), "error: server error", slog.Any("error", err))
		}
	case sig := <-shutdownChan:
		app.Log.Info(context.Background(), "received shutdown signal", slog.String("signal", sig.String()))

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			app.Log.Error(context.Background(), "error during graceful shutdown", slog.Any("error", err))
			app.Log.Info(context.Background(), "attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				app.Log.Error(context.Background(), "error during force close", slog.Any("error", err))
			}
		} else {
			app.Log.Info(context.Background(), "server gracefully shutdown complete")
		}
	}

	return nil
}
```

* File `internal/handler/user_handler.go` berubah menjadi 

```go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHanlder interface {
	List(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	log     logger.Logger
	service service.Users
}

func NewUserHandler(service service.Users, log logger.Logger) UserHanlder {
	return &userHandler{service: service, log: log}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		response = append(response, ur)
	}

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

* File `internal/service/users.go` berubah menjadi 

```go
package service

import (
	"workshop/internal/model"
	"workshop/internal/repository"

	"github.com/jacky-htg/go-libs/logger"
)

type Users interface {
	List() ([]model.User, error)
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List() ([]model.User, error) {
	return u.repo.List()
}
```

* File `internal/repository/user_repository.go` berubah menjadi:

```go
package repository

import (
	"context"
	"database/sql"
	"log/slog"
	"workshop/internal/model"

	"github.com/jacky-htg/go-libs/logger"
)

type UserRepository interface {
	List() ([]model.User, error)
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.db.Query(query)
	if err != nil {
		u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(context.Background(), "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}
```

