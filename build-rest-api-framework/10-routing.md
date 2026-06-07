# Routing

* Jika ada lebih dari satu endpoint, kita membutuhkan routing
* Sejak go 1.22, net/http sudah menyediakan routing yang sangat mudah digunakan, dan mengenali path regex sehingga bisa menerapkan pretty url. 
* Untuk Cli, kita bisa memanfaatkan switch case untuk routing

## Routing Cli

* Buat file `internal/router/cli.go` tang berisi :

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
		return fmt.Errorf("Error: perintah tidak valid")
	}

	return nil
}
```

* Ubah file `cmd/cli/main.go` menjadi :

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
		return fmt.Errorf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %s", err)
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
		return fmt.Errorf("error: executing command: %s", err)
	}

	return nil
}
```

## Routing Rest API

* Buat file `intenral/router.api.go` yang berisi :

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

* Ubah file `cmd/server/main.go` menjadi :

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
		return fmt.Errorf("error: initializing app: %s", err)
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

