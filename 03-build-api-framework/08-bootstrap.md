# Bab 8: Bootstrap

Seiring berkembangnya framework, kita akan menambahkan berbagai komponen: Redis, OpenTelemetry, HTTP client, message queue, dan lain-lain. Jika semua inisialisasi dilakukan langsung di `run()`, fungsi tersebut akan menjadi sangat panjang dan sulit dikelola.

**Bootstrap** adalah pola untuk memusatkan semua inisialisasi aplikasi dalam satu tempat, sehingga `run()` hanya fokus pada **orchestration** (mengatur alur) bukan **construction** (membangun komponen).

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/08-bootstrap](https://github.com/jacky-htg/workshop/tree/main/08-bootstrap)

## 8.1 Masalah dengan Inisialisasi Langsung

Saat ini, `run()` melakukan dua tanggung jawab sekaligus:

```go
func run() error {
    // Tanggung jawab 1: Inisialisasi komponen
    cfg, err := config.LoadConfig()     // ← inisialisasi
    db, err := database.OpenDB(cfg)     // ← inisialisasi
    
    // Tanggung jawab 2: Orchestration
    userRepository := repository.NewUserRepository(db)
    userService := service.NewUsers(userRepository)
    userHandler := handler.NewUserHandler(userService)
    // ... server setup dan graceful shutdown
}
```

**Masalah ke depan:**
- Setiap komponen baru (Redis, cache, telemetry) akan menambah panjang `run()`
- Inisialisasi dan cleanup tersebar (database di sini, nanti Redis di sana)
- Sulit mengatur urutan inisialisasi yang benar (misal: logger harus sebelum yang lain)
- Testing jadi sulit karena harus menginisialisasi semua komponen

## 8.2 Solusi: Struct App sebagai Container

Kita buat struct App yang menjadi container untuk semua dependency aplikasi:

```go
type App struct {
    Config   config.Config
    Database *sql.DB
    // Redis    *redis.Client   (nanti)
    // Logger   *slog.Logger    (nanti)
    // Tracer   trace.Tracer    (nanti)
    
    Cleanup func()  // fungsi untuk membersihkan resource
}
```

**Prinsip:**
- Semua inisialisasi terjadi di fungsi `NewApp()`
- `App` berisi semua komponen yang sudah siap pakai
- `Cleanup` berisi fungsi untuk menutup resource (database, koneksi, dll)
- `run()` cukup memanggil `NewApp()` dan `defer app.Cleanup()`

## 8.3 Implementasi Bootstrap

Buat file `internal/bootstrap/app.go`:

```go
package bootstrap

import (
	"database/sql"
	"fmt"
	"workshop/config"
	"workshop/pkg/database"

	_ "github.com/lib/pq"
)

type App struct {
	Config   config.Config
	Database *sql.DB

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

	return App{
		Config:   cfg,
		Database: db,
		Cleanup: func() {
			if err := db.Close(); err != nil {
				fmt.Printf("error: closing database: %s\n", err)
			}
		},
	}, nil
}
```

**Catatan**: Cleanup function memastikan resource dibersihkan dalam urutan yang benar (LIFO - Last In First Out) ketika dipanggil di `defer`.

## 8.4 Update Server Main

Sekarang `cmd/server/main.go` menjadi lebih bersih. Semua inisialisasi komponen diambil alih oleh `bootstrap.NewApp()`:

```go
package main

import (
	"context"
	"fmt"
	"log"
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
		log.Fatalf("error: running application: %s", err)
	}
}

func run() error {
	app, err := bootstrap.NewApp()
	if err != nil {
		return fmt.Errorf("error: initializing app: %w", err)
	}
	defer app.Cleanup()

	userRepository := repository.NewUserRepository(app.Database)
	userService := service.NewUsers(userRepository)
	userHandler := handler.NewUserHandler(userService)

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
		log.Printf("starting server on %s", server.Addr)
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
			return fmt.Errorf("server error: %w", err)
		}
	case sig := <-shutdownChan:
		log.Printf("received shutdown signal: %s", sig)

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), app.Config.Server.GracefulShutdownTimeout)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
			log.Printf("attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				return fmt.Errorf("error during force close: %w", err)
			}
		} else {
			log.Printf("server gracefully shutdown complete")
		}
	}

	return nil
}
```

## 8.5 Perbandingan Sebelum dan Sesudah

| Aspek | Sebelum Bootstrap	| Sesudah Bootstrap |
|-------|-------------------|-------------------|
| Lokasi inisialisasi | Tersebar di `run()` | Terpusat di `bootstrap.NewApp()` |
| Penambahan komponen baru | Mengubah `run()` | Mengubah `bootstrap.NewApp()` |
| Cleanup resource | Tersebar (hanya database) | Terpusat di `Cleanup()` |
| Testability | Sulit mock komponen | Mudah (bisa buat App terpisah untuk test) |
| Tanggung jawab `run()` | Inisialisasi + orchestrasi | Hanya orchestrasi |

## 8.6 Diagram Alur Bootstrap

```text
┌─────────────────────────────────────────────────────────────────┐
│                         main()                                  │
│                    if err := run()...                           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         run()                                   │
│  1. app, err := bootstrap.NewApp()                              │
│  2. defer app.Cleanup()                                         │
│  3. Setup handler, server, graceful shutdown                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────┐
│                bootstrap.NewApp()                              │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ config.LoadConfig()                                     │   │
│  │         ↓                                                   │
│  │ database.OpenDB(cfg)                                    │   │
│  │         ↓                                               │   │
│  │ redis.NewClient(cfg)             (nanti)                │   │
│  │         ↓                                               │   │
│  │ logger.New(cfg)                  (nanti)                │   │
│  │         ↓                                               │   │
│  │ return App{Config, Database, Cleanup}                   │   │
│  └─────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────┘
```

## 8.7 Update CLI Main (Opsional)

Untuk konsistensi, `cmd/cli/main.go` juga bisa menggunakan bootstrap:

```go
package main

import (
    "flag"
    "fmt"
    "log"
    "workshop/internal/bootstrap"

    "github.com/jacky-htg/go-libs/migration"
)

func main() {
    if err := run(); err != nil {
        log.Fatalf("error: running application: %s", err)
    }
}

func run() error {
    app, err := bootstrap.NewApp()
    if err != nil {
        return fmt.Errorf("initializing app: %w", err)
    }
    defer app.Cleanup()

    flag.Parse()

    if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
        if err := migration.Migrate(app.Database, "migration"); err != nil {
            return fmt.Errorf("running migrations: %w", err)
        }
        log.Printf("migrations completed successfully")
    }

    return nil
}
```

## 8.8 Manfaat untuk Testing (Preview)

Testing memang bleum dibahas di bab ini, tapi sebagai preview saja untuk menjelaskan keuntungan pemakaian bootstrap. Dengan pola bootstrap, testing menjadi lebih mudah karena kita bisa membuat App terpisah untuk test:

```go
func TestUserHandler(t *testing.T) {
    // Setup test database
    testDB := setupTestDB(t)
    
    // Buat App untuk testing
    app := bootstrap.App{
        Config:   testConfig,
        Database: testDB,
        Cleanup:  func() { testDB.Close() },
    }
    
    // Test dengan app yang sudah siap
    // ...
}
```

## Ringkasan Bab 8

Di bab ini kita telah belajar:
1. Masalah – Inisialisasi komponen yang tersebar membuat `run()` panjang dan sulit dikelola
2. Solusi – Bootstrap pattern dengan struct App sebagai container dependency
3. Implementasi – Fungsi `NewApp()` yang menginisialisasi semua komponen sekaligus
4. Cleanup – Fungsi Cleanup untuk membersihkan resource dalam urutan yang benar
5. Testability – Bootstrap memudahkan pembuatan test dengan dependency yang bisa diganti

Manfaat yang kita peroleh:
- ✅ `run()` sekarang hanya fokus pada orchestrasi (setup server, graceful shutdown)
- ✅ Penambahan komponen baru (Redis, logger, dll) hanya mengubah `bootstrap.NewApp()`
- ✅ Cleanup resource terpusat dan terjamin
- ✅ Lebih mudah di-test karena dependency bisa diganti
- ✅ CLI dan server berbagi inisialisasi yang sama

Yang akan datang:
- Saat ini logging masih menggunakan `log.Printf` standar
- Bab selanjutnya: Logging – membangun logging terstruktur untuk memudahkan observabilitas