# Fatal

* log.Fatal menyebabkan program berhenti setelah mencetak sebuah pesan error
* log.Fatal memanggil fungsi os.Exit\(1\) untuk memaksa program berhenti
* Penggunaan log.Fatal untuk menghentikan program sedini mungkin jika ada suatu kesalahan yang menyebabkan suatu kode selanjutnya tidak perlu dieksekusi sama sekali, atau kesalahan yang tidak dapat dipulihkan.
* log.Fatal biasanya hanya ada di dalam func init\(\) atau func main\(\)
* Dalam bab ini, kita akan memastikan bahwa log.Fatal hanya dipanggil di fungsi main, dan hanya dipanggil 1x. Hal ini dimaksudkan agar lebih mudah dalam mengelola kode dan memeriksa kesalahan-kesalahan fatal.
* Pindahkan semua kode main ke `func run() error{}`. 
* Hapus seluruh call log.Fatal dan diganti dengan return error
* Panggil fungsi run\(\) di main\(\), jika terjadi error eksekusi log.Fatal
* Pada file `cmd/cli/main.go` ubah menjadi : 

```go
package main

import (
	"flag"
	"fmt"
	"log"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {
	if err := run(); err != nil {
		log.Fatalf("error: running application: %s", err)
	}
}

func run() error {

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
		log.Printf("migrations completed successfully")
	}

	return nil
}
```

* File cmd/server/main.go berubah menjadi seperti berikut :

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
	if err := run(); err != nil {
		log.Fatalf("error: running application: %s", err)
	}
}

func run() error {
	cfg, err := config.LoadConfig()
	if err != nil {
		return fmt.Errorf("error: loading config: %s", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %s", err)
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

	return nil
}
```

