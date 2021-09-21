# Configuration
- Di production, semua konfigurasi dimasukkan ke dalam environment server
- Di development, semua konfigurasi akan dibaca dari file .env
- Konfigurasi yang akan diatur meliputi : port server, database driver, database connection
- Sebagai tambahan dibuat konfigurasi penanda apakah aplikasi berjalan di production atau lokal
- Buat file .env yang isinya 

```
APP_PORT=0.0.0.0:9000
APP_ENV=local

DB_DRIVER=mysql
DB_SOURCE=root:pass@tcp(localhost:3306)/essentials?parseTime=true
```

- Kemudian buat library untuk membaca file .env dan menyalinnya ke environment OS. Buat file libraries/config/config.go

```
package config

import (
	"io/ioutil"
	"os"
	"strings"
)

//Setup environment from file .env
func Setup(file string) error {
	data, err := ioutil.ReadFile(file)
	if err != nil {
		return err
	}

	datas := strings.Split(string(data), "\n")
	for _, env := range datas {
		e := strings.Split(env, "=")
		if len(e) >= 2 {
			os.Setenv(strings.TrimSpace(e[0]), strings.TrimSpace(strings.Join(e[1:], "=")))
		}
	}

	return nil
}
```

- Ubah file main.go agar meload file .env jika environment-nya developement atau lokal, dengan menyisipkan kode

```
if _, ok := os.LookupEnv("APP_ENV"); !ok {
	config.Setup(".env")
}
```

- Ubah file main.go agar membaca env port saat membuat parameter server

```
    server := http.Server{
		Addr:         os.Getenv("APP_PORT"),
		Handler:      http.HandlerFunc(service.List),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}
```

- File main.go akan menjadi seperti ini 

```
package main

import (
	"context"
	"essentials/controllers"
	"essentials/libraries/config"
	"essentials/libraries/database"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	if _, ok := os.LookupEnv("APP_ENV"); !ok {
		config.Setup(".env")
	}

	// =========================================================================
	// App Starting

	log.Printf("main : Started")
	defer log.Println("main : Completed")

	// =========================================================================

	// Start Database

	db, err := database.Open()
	if err != nil {
		log.Fatalf("error: connecting to db: %s", err)
	}
	defer db.Close()

	// Create variable service with pattern dependency injection.
	// Inject koneksion db to type of Users
	service := controllers.Users{Db: db}

	// parameter server
	server := http.Server{
		Addr:         os.Getenv("APP_PORT"),
		Handler:      http.HandlerFunc(service.List),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrors := make(chan error, 1)
	// mulai listening server
	go func() {
		log.Println("server listening on", server.Addr)
		serverErrors <- server.ListenAndServe()
	}()

	// Membuat channel untuk mendengarkan sinyal interupsi/terminate dari OS.
	// Menggunakan channel buffered karena paket signal membutuhkannya.
	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, os.Interrupt, syscall.SIGTERM)

	// Mengontrol penerimaan data dari channel,
	// jika ada error saat listenAndServe server maupun ada sinyal shutdown yang diterima
	select {
	case err := <-serverErrors:
		log.Fatalf("error: listening and serving: %s", err)

	case <-shutdown:
		log.Println("caught signal, shutting down")

		// Jika ada shutdown, meminta tambahan waktu 5 detik untuk menyelesaikan proses yang sedang berjalan.
		const timeout = 5 * time.Second
		ctx, cancel := context.WithTimeout(context.Background(), timeout)
		defer cancel()

		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error: gracefully shutting down server: %s", err)
			if err := server.Close(); err != nil {
				log.Printf("error: closing server: %s", err)
			}
		}
	}

	log.Println("done")
}
```

- Ubah file libraries/database/database.go 

```
package database

import (
	"database/sql"
	"os"
)

//Open : open database
func Open() (*sql.DB, error) {
	return sql.Open(os.Getenv("DB_DRIVER"), os.Getenv("DB_SOURCE"))
}
```

- Ubah file cmd/main.go menjadi seperti berikut :

```
package main

import (
	"essentials/libraries/config"
	"essentials/libraries/database"
	"essentials/schema"
	"flag"
	"log"
	"os"

	_ "github.com/go-sql-driver/mysql"
)

func main() {

	if _, ok := os.LookupEnv("APP_ENV"); !ok {
		config.Setup(".env")
	}

	// Start Database
	db, err := database.Open()
	if err != nil {
		log.Fatalf("error: connecting to db: %s", err)
	}
	defer db.Close()

	// Handle cli command
	flag.Parse()

	switch flag.Arg(0) {
	case "migrate":
		if err := schema.Migrate(db); err != nil {
			log.Println("error applying migrations", err)
			os.Exit(1)
		}
		log.Println("Migrations complete")
		return

	case "seed":
		if err := schema.Seed(db); err != nil {
			log.Println("error seeding database", err)
			os.Exit(1)
		}
		log.Println("Seed data complete")
		return
	}
}

```