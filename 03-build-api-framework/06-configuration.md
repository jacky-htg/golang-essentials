# Configuration

* Di production, semua konfigurasi dimasukkan ke dalam environment server
* Di development, semua konfigurasi akan dibaca dari file .env
* Konfigurasi yang akan diatur meliputi : port server, database driver, database connection
* Buat file .env yang isinya 

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

* Ada banyak library untuk membaca konfigurasi env, saya akan menggunakan godotenv yang cukup simple penggunaan-nya. Untuk menjaga agar aplikasi tidak error ketika env tidak diset, saya membuat library yang melakukan wrapping env. Kemudian buat file config/config.go

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
	AppPort int

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

* Ubah file pkg/database/postgre.go agar membaca env. Juga kita tambahkan konfigurasi lainnya yang akan berguna dalam pengaturan koneksi database.

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

* Ubah file cmd/cli/main.go agar meload konfigurasi

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

* Ubah file cmd/server/main.go agar membaca konfigurasi

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
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
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