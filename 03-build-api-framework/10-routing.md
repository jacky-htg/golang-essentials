# Bab 10: Routing

Sejauh ini API kita hanya memiliki satu endpoint: `GET /users`. Aplikasi nyata membutuhkan banyak endpoint dengan berbagai method HTTP (GET, POST, PUT, DELETE) dan parameter di URL (seperti `/users/{id}`). Di sinilah routing berperan.

Bab ini akan membangun dua jenis router:
1. CLI Router – untuk perintah command line (migrate, seed, scheduler dll)
2. HTTP Router – untuk REST API endpoints

## 10.1 Routing di Go 1.22+

Sejak Go 1.22, package `net/http` memiliki kemampuan routing yang jauh lebih baik. Kita tidak perlu lagi library eksternal seperti `gorilla/mux` atau `chi` untuk kebutuhan dasar.

Fitur baru yang tersedia:
- Method-based routing: `mux.HandleFunc("GET /users", handler)`
- Path parameters: `mux.HandleFunc("GET /users/{id}", handler)`
- Wildcard: `mux.HandleFunc("GET /files/{path...}", handler)`

## 10.2 CLI Router

CLI router bertugas memetakan perintah dari terminal ke fungsi yang sesuai. Pola yang umum digunakan adalah switch-case berdasarkan argumen pertama.

### Struktur Router CLI

Buat file `internal/router/cli.go:`

```go
package router

import (
	"context"
	"database/sql"
	"fmt"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/migration"
)

func Cli(
	db *sql.DB,
	log logger.Logger,
	command string,
	args []string) error {

	switch command {
	case "migrate":
		err := migration.Migrate(db, "migration")
		if err != nil {
			log.Error(context.Background(), "Migration failed", "error", err)
			return err
		}
		log.Info(context.Background(), "Migration completed successfully")
	default:
		return fmt.Errorf("unknown command: %s", command)
	}

	return nil
}
```

### Update CLI Main

`cmd/cli/main.go` sekarang lebih sederhana — hanya bertugas parsing argumen dan memanggil router:

```go
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"workshop/config"
	"workshop/internal/router"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/logger"
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
	args := flag.Args()
	if len(args) == 0 {
		return fmt.Errorf("Usage: program <command> [arguments...]")
	}

	command := args[0]
	commandArgs := args[1:]

	if err := router.Cli(db, log, command, commandArgs); err != nil {
		return fmt.Errorf("error: executing command: %w", err)
	}

	return nil
}
```

Penggunaan CLI sekarang:

```bash
# Menjalankan migration
go run cmd/cli/main.go migrate

# Output: "Migration completed successfully"
```

## 10.3 HTTP Router (REST API)

HTTP router memetakan method HTTP + path ke handler function. Dengan Go 1.22+, kita bisa menulis routing yang ekspresif dan aman tipe.

### Struktur Router API

Buat file `internal/router/api.go`:

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)

	return mux
}
```

**Sintaks Method + Path**: "GET /users" adalah format baru di Go 1.22+. Jika method tidak cocok, otomatis mengembalikan 405 Method Not Allowed.

### Update Server Main

`cmd/server/main.go` sekarang lebih bersih — cukup memanggil `router.Api()` sebagai handler:

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
	"workshop/internal/router"
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

	server := &http.Server{
		Addr:         fmt.Sprintf("0.0.0.0:%d", app.Config.Server.AppPort),
		Handler:      router.Api(app.Config, app.Database, app.Log),
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

## 10.4 Menambahkan Endpoint Baru (Contoh)

Untuk menambahkan endpoint baru, cukup tambahkan di `router/api.go`:

```go
// GET /users/{id} - mengambil satu user berdasarkan ID
mux.HandleFunc("GET /users/{id}", userHandler.Get)

// POST /users - membuat user baru
mux.HandleFunc("POST /users", userHandler.Create)

// PUT /users/{id} - mengupdate user
mux.HandleFunc("PUT /users/{id}", userHandler.Update)

// DELETE /users/{id} - menghapus user
mux.HandleFunc("DELETE /users/{id}", userHandler.Delete)
```

Dan implementasikan method handler yang sesuai di `user_handler.go`.

## 10.5 Struktur Direktori Setelah Routing

```text
workshop/
├── cmd/
│   ├── cli/
│   │   └── main.go          # Memanggil router.Cli()
│   └── server/
│       └── main.go          # Memanggil router.Api()
├── internal/
│   ├── bootstrap/
│   │   └── app.go
│   ├── dto/
│   │   └── user_response.go
│   ├── handler/
│   │   └── user_handler.go
│   ├── model/
│   │   └── user.go
│   ├── repository/
│   │   └── user_repository.go
│   ├── router/
│   │   ├── api.go           ← baru (HTTP router)
│   │   └── cli.go           ← baru (CLI router)
│   └── service/
│       └── users.go
├── migration/
├── pkg/
└── config/
```

## 10.6 Perbandingan Sebelum dan Sesudah

| Aspek | Sebelum | Sesudah |
|-------|---------|---------|
| CLI handling | Langsung di `main()` | `router.Cli()` dengan switch-case |
| HTTP routing | Hardcoded di `main()` | `router.Api()` terpusat |
| Method support | Semua method masuk ke handler yang sama | Method-specific routing |
| Path parameters | Tidak ada (parsing manual) | Dukungan bawaan `/{id}` |
| Penambahan endpoint | Mengubah `main()` | Cukup tambah di `router.Api()` |

## Ringkasan Bab 10

Di bab ini kita telah belajar:
1. CLI Router – Memetakan perintah terminal ke fungsi menggunakan switch-case
2. HTTP Router (Go 1.22+) – Routing dengan method + path dalam satu baris
3. Pemisahan tanggung jawab – Router bertugas memetakan, handler tetap fokus pada logika bisnis
4. Struktur yang scalable – Endpoint baru mudah ditambahkan tanpa mengganggu main()

Manfaat yang kita peroleh:
- ✅ CLI dan HTTP routing terpisah dan terorganisir
- ✅ Sintaks routing Go 1.22+ lebih bersih dan tidak perlu library eksternal
- ✅ main() menjadi sangat ramping — hanya orchestrasi
- ✅ Persiapan untuk CRUD lengkap di bab berikutnya

Yang akan datang:
- Saat ini kita hanya memiliki endpoint GET /users (READ)
- Bab selanjutnya: CRUD – menambahkan Create, Read (by ID), Update, dan Delete untuk resource User