# Bab 6: Configuration

Selama ini kita menulis konfigurasi seperti port server dan koneksi database secara hardcoded — langsung ditulis dalam kode. Ini masalah besar karena:

- Berbeda antara laptop developer (development) dengan server produksi
- Konfigurasi rahasia (password database) tidak boleh masuk ke repository Git
- Mengganti konfigurasi memerlukan compile ulang kode

Solusinya adalah membaca konfigurasi dari environment variable dan file `.env`.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/06-configuration](https://github.com/jacky-htg/workshop/tree/main/06-configuration)

## 6.1 Strategi Konfigurasi

| Lingkungan | Sumber Konfigurasi | Contoh |
|------------|--------------------|--------|
| **Development** | File `.env` | `APP_PORT=9000` |
| **Production** | Environment variable | `export APP_PORT=8080` |
| **Container (Docker)** | Environment variable | `-e APP_PORT=8080` |

Pola yang akan kita terapkan:
1. Baca file `.env` jika ada (untuk development)
2. Jika variabel yang sama diset di environment, nilai environment lebih prioritas
3. Setiap konfigurasi memiliki nilai default (fallback)

## 6.2 Library yang Digunakan

Kita akan menggunakan dua library:
- `godotenv` – membaca file `.env`
- `go-libs/env` – wrapper yang memberi prioritas ke environment variable

Install dependency:

```bash
go get github.com/jacky-htg/go-libs/env
go get github.com/joho/godotenv
```

## 6.3 File .env

Buat file `.env` di root proyek:

```text
APP_PORT=9000
SERVER_WRITE_TIMEOUT=15s
SERVER_READ_TIMEOUT=15s
SERVER_IDLE_TIMEOUT=30s
SERVER_GRACEFUL_SHUTDOWN_TIMEOUT=30s

DB_HOST=localhost
DB_PORT=5432
DB_USERNAME=postgres
DB_PASSWORD=1234
DB_DATABASE=workshop
DB_SSLMODE=disable
DB_SCHEMA=public
DB_APPLICATION_NAME=workshop
DB_MAX_OPEN_CONNS=25
DB_MAX_IDLE_CONNS=25
DB_CONN_MAX_LIFETIME=5m
DB_CONN_MAX_IDLE_TIME=5m
```

**Keamanan:** Jangan commit file `.env` ke Git! Tambahkan `.env` ke `.gitignore`.

## 6.4 Struct Konfigurasi

Buat folder `config/` dan file `config/config.go`. Struct ini akan mengelompokkan konfigurasi berdasarkan domainnya:

```go
package config

import (
	"time"

	"github.com/jacky-htg/go-libs/env"
)

type Config struct {
	Server   ServerConfig
	Database DatabaseConfig
}

type ServerConfig struct {
	AppPort 				int
	WriteTimeout            time.Duration
	ReadTimeout             time.Duration
	IdleTimeout             time.Duration
	GracefulShutdownTimeout time.Duration
}

type DatabaseConfig struct {
	Host            string
	Port            string
	Username        string
	Password        string
	Database        string
	SslMode         string
	Schema          string
	ApplicationName string
	MaxOpenConns    int
	MaxIdleConns    int
	ConnMaxLifetime time.Duration
	ConnMaxIdleTime time.Duration
}
```

**Pola ini penting:** Dengan mengelompokkan konfigurasi, kita bisa dengan mudah melewatkan cfg.Server atau cfg.Database ke fungsi yang membutuhkan, bukan seluruh Config.

## 6.5 Fungsi LoadConfig

Fungsi `LoadConfig` akan membaca environment (dan file `.env` jika ada), lalu mengembalikan struct Config yang sudah terisi:

```go
func LoadConfig() (Config, error) {
    err := env.InitEnv()
    if err != nil {
        return Config{}, err
    }

    server := ServerConfig{
        AppPort:                 env.EnvInt("APP_PORT", 9000),
        WriteTimeout:            env.EnvDuration("SERVER_WRITE_TIMEOUT", 5*time.Second),
        ReadTimeout:             env.EnvDuration("SERVER_READ_TIMEOUT", 5*time.Second),
        IdleTimeout:             env.EnvDuration("SERVER_IDLE_TIMEOUT", 30*time.Second),
        GracefulShutdownTimeout: env.EnvDuration("SERVER_GRACEFUL_SHUTDOWN_TIMEOUT", 30*time.Second),
    }

    databaseConfig := DatabaseConfig{
        Host:            env.Env("DB_HOST", "localhost"),
        Port:            env.Env("DB_PORT", "5432"),
        Username:        env.Env("DB_USERNAME", "postgres"),
        Password:        env.Env("DB_PASSWORD", "1234"),
        Database:        env.Env("DB_DATABASE", "workshop"),
        SslMode:         env.Env("DB_SSLMODE", "disable"),
        Schema:          env.Env("DB_SCHEMA", "public"),
        ApplicationName: env.Env("DB_APPLICATION_NAME", "workshop"),
        MaxOpenConns:    env.EnvInt("DB_MAX_OPEN_CONNS", 25),
        MaxIdleConns:    env.EnvInt("DB_MAX_IDLE_CONNS", 25),
        ConnMaxLifetime: env.EnvDuration("DB_CONN_MAX_LIFETIME", 5*time.Minute),
        ConnMaxIdleTime: env.EnvDuration("DB_CONN_MAX_IDLE_TIME", 5*time.Minute),
    }

    return Config{
        Server:   server,
        Database: databaseConfig,
    }, nil
}
```

Perhatikan setiap nilai memiliki **fallback default** — parameter kedua di `env.EnvInt`, `env.EnvDuration`, dll.

## 6.6 Update Package Database

Ubah `pkg/database/postgre.go` untuk menerima konfigurasi:

```go
package database

import (
	"database/sql"
	"fmt"

	"workshop/config"
)

func OpenDB(cfg config.Config) (*sql.DB, error) {
	db, err := sql.Open(
		"postgres",
		fmt.Sprintf(
			"host=%s port=%s user=%s password=%s dbname=%s sslmode=%s search_path=%s application_name=%s",
			cfg.Database.Host,
			cfg.Database.Port,
			cfg.Database.Username,
			cfg.Database.Password,
			cfg.Database.Database,
			cfg.Database.SslMode,
			cfg.Database.Schema,
			cfg.Database.ApplicationName,
		),
	)

	db.SetMaxOpenConns(cfg.Database.MaxOpenConns)
	db.SetMaxIdleConns(cfg.Database.MaxIdleConns)
	db.SetConnMaxLifetime(cfg.Database.ConnMaxLifetime)
	db.SetConnMaxIdleTime(cfg.Database.ConnMaxIdleTime)

	err = db.Ping()
	if err != nil {
		return nil, fmt.Errorf("Failed to ping %s DB: %v", cfg.Database.Database, err)
	}
	return db, nil
}
```

Penjelasan tambahan tentang connection pool:
- MaxOpenConns – maksimal koneksi aktif ke database (25 adalah nilai yang baik untuk aplikasi skala sedang)
- MaxIdleConns – koneksi idle yang disimpan untuk dipakai ulang
- ConnMaxLifetime – maksimal umur koneksi (mencegah koneksi stale)
- ConnMaxIdleTime – waktu maksimal koneksi idle sebelum ditutup

## 6.7 Update CLI dan Server

### `cmd/cli/main.go`

```go
package main

import (
	"flag"
	"log"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {

	cfg, err := config.LoadConfig()
	if err != nil {
		log.Fatalf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		log.Fatalf("error: opening database: %s", err)
	}
	defer db.Close()

	flag.Parse()

	if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
		if err := migration.Migrate(db, "migration"); err != nil {
			log.Fatalf("error: running migrations: %s", err)
		}
		log.Printf("migrations completed successfully")
		return
	}
}
```

### `cmd/server/main.go`

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
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
	"workshop/pkg/database"

	_ "github.com/lib/pq"
)

func main() {
	cfg, err := config.LoadConfig()
	if err != nil {
		log.Fatalf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		log.Fatalf("error: opening database: %s", err)
	}
	defer db.Close()

	userRepository := repository.NewUserRepository(db)
	userService := service.NewUsers(userRepository)
	userHandler := handler.NewUserHandler(userService)

	// server
	server := &http.Server{
		Addr:         fmt.Sprintf("0.0.0.0:%d", cfg.Server.AppPort),
		Handler:      http.HandlerFunc(userHandler.List),
		ReadTimeout:  cfg.Server.ReadTimeout,
		WriteTimeout: cfg.Server.WriteTimeout,
	}

	serverErrChan := make(chan error, 1)

	// start server in a goroutine
	go func() {
		log.Printf("starting server on %s", server.Addr)
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
			log.Fatalf("error: server error: %s", err)
		}
	case sig := <-shutdownChan:
		log.Printf("received shutdown signal: %s", sig)

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), cfg.Server.GracefulShutdownTimeout)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
			log.Printf("attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				log.Printf("error during force close: %v", err)
			}
		} else {
			log.Printf("server gracefully shutdown complete")
		}
	}
}
```

Perhatikan bahwa `GracefulShutdownTimeout` sekarang juga dibaca dari konfigurasi (tidak hardcoded 30 detik lagi).

## 6.8 Menjalankan Aplikasi

```bash
# Jalankan dengan konfigurasi default dari .env
go run cmd/server/main.go

# Override port via environment variable
APP_PORT=8080 go run cmd/server/main.go

# Migration juga membaca konfigurasi
go run cmd/cli/main.go migrate
```

## Ringkasan Bab 6

Di bab ini kita telah belajar:

| Konsep | Implementasi |
|--------|--------------|
| Environment variable | Prioritas tertinggi, aman untuk production |
| File .env | Untuk development, tidak di-commit |
| Default values | Fallback jika variabel tidak diset |
| Struct grouping | `ServerConfig`, `DatabaseConfig` – mudah di-passing |
| Connection pool | `SetMaxOpenConns`, `SetMaxIdleConns`, dll |

Manfaat yang kita peroleh:
- ✅ Tidak ada lagi hardcoded configuration
- ✅ Password database bisa disimpan di environment (aman)
- ✅ Port, timeout, dan koneksi database bisa diubah tanpa recompile
- ✅ Connection pool database bisa diatur sesuai beban
- ✅ Satu kode berjalan di development dan production

Yang akan datang:
- ❌ Belum ada log yang terstruktur (masih pakai log.Printf)
- ❌ Belum ada error handling yang konsisten

Pada bab berikutnya, kita akan membahas pola penggunaan log.Fatal yang disiplin dan terpusat.