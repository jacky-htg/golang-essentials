# Bootstrap

* Saat ini fungsi main terasa panjang karena ada beberapa init objek untuk kepentingan dependency injection, yaitu init config dan init database.
* Saat ini memang hanya ada dua inisiasi, tapi ke depannya kita akan membutuhkan lebih banyak inisiasi, seperti redis, telemetri, http client dan lain-lain.
* Jika semakin banyak yang diinisiasi, maka fungsi main akan semakin panjang. Untuk arsitektur yang lebih clean, kita introduce bootstrap yang akan digunakan untuk insiasi semua yang dibutuhkan oleh aplikasi.
* Buat file internal/bootstrap/app.go yang berisi :

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
		return App{}, fmt.Errorf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return App{}, fmt.Errorf("error: opening database: %s", err)
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

* File cmd/server/main.go, pada fungsi main, semua inisiasi bisa dialihkan ke bootstrap sehingga menjadi:

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
		return fmt.Errorf("error: initializing app: %s", err)
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

	return nil
}
```