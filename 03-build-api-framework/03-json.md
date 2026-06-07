# Json

* JSON adalah format response API yang cukup populer. Bab ini kita akan membuat response dalam format json.
* Sebagai sample, kita akan membuang/menghapus handler HelloWorld dan menggantinya dengan handler ListUsers
* Membuat type struct User
* Membuat handler ListUsers untuk menampilkan list users 
* Buat project dengan nama workshop `go mod init worskhop`
* Buat file main.go dengan isi seperti di bawah ini
* Jalankan `go mod tidy` baru kemudian menjalankan `go run main.go`

```go
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jacky-htg/go-libs/uuid7"
)

func main() {

	// server
	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
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

type User struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

// ListUsers : http handler for returning list of users
func ListUsers(w http.ResponseWriter, r *http.Request) {
	users := []User{
		{ID: uuid7.New(), Name: "John Doe", Username: "johndoe", Password: "secret", Email: "john.doe@example.com", IsActive: true},
		{ID: uuid7.New(), Name: "Jane Smith", Username: "janesmith", Password: "secret", Email: "jane.smith@example.com", IsActive: false},
	}

	data, err := json.Marshal(users)
	if err != nil {
		log.Printf("error: marshaling users to JSON: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		log.Printf("error: writing response: %s", err)
	}
}
```

