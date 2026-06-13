# Bab 9: Logging

Logging adalah mata dan telinga aplikasi kita di production. Tanpa log yang baik, kita buta terhadap apa yang terjadi: error tidak terdeteksi, performa tidak terukur, dan debugging menjadi mimpi buruk.

Di bab ini kita akan meningkatkan sistem logging dari `log.Printf` biasa menjadi structured logging dengan `slog.Logger`.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/09-logging](https://github.com/jacky-htg/workshop/tree/main/09-logging)

## 9.1 Masalah dengan Logging Saat Ini

Sejauh ini kita menggunakan `log.Printf` dan `log.Fatalf` dari package log standar. Pendekatan ini memiliki dua kelemahan utama:

| Masalah | Penjelasan | Dampak |
|---------|------------|--------|
| Variabel global | `log` package menggunakan logger global | Sulit di-test, tidak bisa disuntikkan (dependency injection) |
| Hanya teks | Output berupa string tidak terstruktur | Tool monitoring (Loki, Elasticsearch) sulit memparsing |

Paket log standard bisa secara mudah dibuat menjadi variabel lokal, namun sulit untuk mendukung format struktured log.

Contoh log teks (sulit diparsing):

```text
2024/01/15 10:30:45 error: querying users: connection refused
```

Contoh log terstruktur (mudah diparsing):

```json
{"time":"2024-01-15T10:30:45Z","level":"ERROR","msg":"error: querying users","error":"connection refused","component":"repository"}
```

## 9.2 Mengenal `slog.Logger`

Go 1.21+ memperkenalkan package `log/slog` (Structured Logging) yang menjadi standar bawaan. Keunggulannya:
- Multiple output formats – Teks (human-friendly) atau JSON (machine-friendly)
- Log levels – Debug, Info, Warn, Error
- Structured fields – Menambahkan key-value pairs ke setiap log
- Context-aware – Bisa mengambil nilai dari context.Context

Kita akan menggunakan library wrapper dari [go-libs/logger](https://github.com/jacky-htg/go-libs/blob/main/logger/logger.go) untuk kemudahan:

```go
// Inisialisasi logger
log := logger.InitLogger(nil)

// Log dengan level dan field terstruktur
log.Info(ctx, "user created", slog.String("user_id", user.ID))
log.Error(ctx, "database error", slog.Any("error", err))
```

## 9.3 Dependency Injection untuk Logger

Prinsip penting: Logger juga harus di-inject, bukan global. Mengapa?
- Testing bisa menggunakan logger mock
- Setiap komponen bisa memiliki konteks sendiri (misal: menambahkan component field)
- Tidak ada hidden dependency

### Langkah 1: Update Bootstrap

Tambahkan Log field ke struct App dan inisialisasi di `NewApp()`:

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
		return App{}, fmt.Errorf("error: loading config: %w", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return App{}, fmt.Errorf("error: opening database: %w", err)
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

### Langkah 2: Propagasikan Logger ke Semua Layer

Logger harus di-passing dari `main()` → bootstrap → handler → service → repository.

Update `cmd/server/main.go`:

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
		return fmt.Errorf("error: initializing app: %w", err)
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
			serverErrChan <- fmt.Errorf("error: listening and serving: %w", err)
		}
		close(serverErrChan)
	}()

	shutdownChan := make(chan os.Signal, 1)
	signal.Notify(shutdownChan, os.Interrupt, syscall.SIGTERM)

	select {
	case err, ok := <-serverErrChan:
		if ok && err != nil {
			app.Log.Error(context.Background(), "error: server error", slog.Any("error", err))
			return err
		}
	case sig := <-shutdownChan:
		app.Log.Info(context.Background(), "received shutdown signal", slog.String("signal", sig.String()))

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), app.Config.Server.GracefulShutdownTimeout)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			app.Log.Error(context.Background(), "error during graceful shutdown", slog.Any("error", err))
			app.Log.Info(context.Background(), "attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				app.Log.Error(context.Background(), "error during force close", slog.Any("error", err))
				return err
			}
		} else {
			app.Log.Info(context.Background(), "server gracefully shutdown complete")
		}
	}

	return nil
}
```

### Langkah 3: Update Repository Layer

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

### Langkah 4: Update Service Layer

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

### Langkah 5: Update Handler Layer

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

### Langkah 6: Update CLI Main

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
		return fmt.Errorf("error: loading config: %w", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %w", err)
	}
	defer db.Close()

	flag.Parse()

	if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
		if err := migration.Migrate(db, "migration"); err != nil {
			return fmt.Errorf("error: running migrations: %w", err)
		}
		log.Info(context.Background(), "migrations completed successfully")
	}

	return nil
}
```

### 9.4 Format Output: Teks vs JSON

`logger.InitLogger(nil)` secara default menghasilkan output teks yang mudah dibaca manusia. Untuk production, kita bisa mengubah ke format JSON:

```go
// Untuk production (JSON)
log := logger.InitLogger(&logger.Config{
    Format: "json",
    Level:  "info",
})

// Untuk development (teks dengan warna)
log := logger.InitLogger(&logger.Config{
    Format: "text",
    Level:  "debug",
})
```

Contoh output JSON:

```json
{"time":"2024-01-15T10:30:45Z","level":"INFO","msg":"starting server","addr":"0.0.0.0:9000"}
{"time":"2024-01-15T10:30:46Z","level":"DEBUG","msg":"listing users"}
{"time":"2024-01-15T10:30:46Z","level":"INFO","msg":"users listed successfully","count":2}
```

## 9.5 Hierarki Log Levels

`slog.Logger` mendukung level logging yang bisa dikonfigurasi:

| Level | Penggunaan |
|-------|------------|
| Debug | Informasi detail untuk debugging (hanya di development) |
| Info | Informasi normal (server started, user created, dll) |
| Warn | Kejadian tidak normal tapi tidak fatal (retry, timeout) |
| Error | Error yang perlu diinvestigasi |

### Ringkasan Bab 9

Di bab ini kita telah belajar:

| Konsep | Implementasi |
|--------|--------------|
| Structured logging | Menggunakan `slog.Logger` dengan format JSON |
| Dependency injection | Logger di-passing dari main() ke semua layer |
| Log levels | Debug, Info, Warn, Error |
| Context fields | Menambahkan field terstruktur ke setiap log |
| Bootstrap integration | Logger menjadi bagian dari struct App |

Manfaat yang kita peroleh:
- ✅ Log terstruktur (JSON) → mudah diintegrasikan dengan tool monitoring
- ✅ Logger di-inject → mudah di-test dan diganti implementasinya
- ✅ Log levels → bisa filter sesuai kebutuhan (debug di dev, info/warn di prod)
- ✅ Konteks yang kaya → setiap log bisa membawa field tambahan (user_id, request_id, dll)

Yang akan datang:
- Saat ini semua log menggunakan context.Background()
- Bab selanjutnya: Routing – membangun router HTTP sendiri untuk mendukung multiple endpoints dengan method (GET, POST, PUT, DELETE) dan path parameters