# Pembuatan API Framework : Bagian Pertama
Di materi sebelumnya, kita telah membuat project melalui perintah `go mod init essentials`. Jadi dalam project pembuatan framework API ini, kita memakai 'essentials' sebagai nama project.

## Start up
- Start up me-listen semua domain, port 9000 dan menghandle-nya dengan fungsi helloworld 
- Untuk start up, kita menggunakan fungsi-fungsi pada [paket net/http](https://golang.org/pkg/net/http), yaitu http.HandlerFunc() dan http.ListenAndServe()

```
package main

import (
	"fmt"
	"log"
	"net/http"
)

func main() {

	// handler
	handler := http.HandlerFunc(helloworld)

	// start server listening
	if err := http.ListenAndServe("0.0.0.0:9000", handler); err != nil {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler with response hello world string
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
```
- Kita juga bisa mendefinisikan parameter parameter untuk menjalankan server http melalui struct [http.Server](https://golang.org/pkg/net/http/#Server)
```
package main

import (
	"fmt"
	"log"
	"net/http"
	"time"
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(helloworld),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	// mulai listening server
	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
```
- Listening server bisa dijalankan secara asynchronous melalui go routine. Dan untuk menangkap error yang terjadi digunakan channel.
```
package main

import (
	"fmt"
	"log"
	"net/http"
	"time"
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(helloworld),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrors := make(chan error, 1)
	// mulai listening server
	go func() {
		log.Println("server listening on", server.Addr)
		serverErrors <- server.ListenAndServe()
	}()

	if err, ok := <-serverErrors; ok {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
``` 

## Shutdown
- Gracefull shutdown. Jika server tiba-tiba di-shutdown, kita bisa meminta waktu untuk menyelesaikan proses yang sedang dikerjakan terlebih dahulu.
- Untuk mengetahui apakah server di-shutdown, kita listening sinyal dari OS. Dan menerimanya melalui channel.
- Karena sekarang ada lebih dari satu channel, kita akan mengontrolnya melalui perintah SELECT.

```
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
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(helloworld),
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

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
```  

## Json
- JSON adalah format response API yang cukup populer. Bab ini kita akan membuat response dalam format json.
- Sebagai sample, kita akan membuang/menghapus handler HelloWorld dan menggantinya dengan handler ListUsers
- Membuat type struct User
- Membuat handler ListUsers untuk menampilkan list users    
```
package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
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

// User : struct of User
type User struct {
	ID       uint
	Username string
	Password string
	Email    string
	IsActive bool
}

// ListUsers : http handler for returning list of users
func ListUsers(w http.ResponseWriter, r *http.Request) {
	list := []User{
		{ID: 1, Username: "jackyhtg", Email: "jacky@htg.com", IsActive: true},
		{ID: 2, Username: "jetlee", Email: "jet@lee.com", IsActive: true},
	}

	data, err := json.Marshal(list)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}

```
## Database
Database yang digunakan dalam materi ini adalah database mysql versi 8. Query-query yang digunakan akan memaksimalkan query native pada paket database/sql bawaan golang. Pertimbangannya, paket database/sql sudah cukup mudah untuk digunakan, dan performancenya sangat baik.

Pembahasan database dibagi dalam 3 bahasan, yaitu: pembuatan migration, seed dan implementasi ListUsers dimana datanya diambil dari database.

- Langkah pertama adalah membuat database. Buka mysql dan buatlah schema : "essentials"
- Kemudian buat koneksi database dengan membuat fungsi openDb(). Misalkan user=root dan password=pass.
```
func openDB() (*sql.DB, error) {
	return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}
```

### Migration
- Ada banyak library yang mengerjakan proses migration. Kali ini saya akan menggunakan library [darwin](https://github.com/GuiaBolso/darwin) karena cukup simple.
- Buat folder schema. Kemudian buatlan file schema/migrate.go yang isinya sebagai berikut :
```
// file schema/migrate.go
package schema

import (
	"database/sql"

	"github.com/GuiaBolso/darwin"
)

var migrations = []darwin.Migration{
	{
		Version:     1,
		Description: "Add users",
		Script: `
CREATE TABLE users (
	id   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	username         CHAR(15) NOT NULL UNIQUE,
	password         varchar(255) NOT NULL,
	email     VARCHAR(255) NOT NULL UNIQUE,
	is_active TINYINT(1) NOT NULL DEFAULT '0',
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	updated TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id)
);`,
	},
}

// Migrate attempts to bring the schema for db up to date with the migrations
// defined in this package.
func Migrate(db *sql.DB) error {
	driver := darwin.NewGenericDriver(db, darwin.MySQLDialect{})

	d := darwin.New(driver, migrations, nil)

	return d.Migrate()
}
```

- Di file main.go akan dilakukan perubahan agar mampu mendukung dua perintah, yaitu perintah listenAndServe http serta perintah migrate yang jalan di console. Perintah flag.Parse() digunakan untuk menangkap parsing parameter yang dilempar dari console.
- Kemudian bandingkan argumen yang diterima, jika argumen == migrate maka eksekusi schema/Migrate() dan setelah selesai diberi perintah return agar baris kode selanjutnya tidak dieksekusi.
- Selain argumen == migrate, akan diabaikan sehingga baris kode yang dijalankan adalah baris http listenAndServe.
- Karena shema/Migrate() membutuhkan koneksi database, maka di awal fungsi utama akan dilakukan pemanggilan fungsi koneksi database.
- Jangan lupa untuk mengimport paket essentials/schema dan _ "github.com/go-sql-driver/mysql"

```
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"essentials/schema"
	"flag"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	// =========================================================================
	// App Starting

	log.Printf("main : Started")
	defer log.Println("main : Completed")

	// =========================================================================

	// Start Database

	db, err := openDB()
	if err != nil {
		log.Fatalf("error: connecting to db: %s", err)
	}
	defer db.Close()

	// Handle cli command
	flag.Parse()

	if flag.Arg(0) == "migrate" {
		if err := schema.Migrate(db); err != nil {
			log.Println("error applying migrations", err)
			os.Exit(1)
		}
		log.Println("Migrations complete")
		return
	}

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
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

// User : struct of User
type User struct {
	ID       uint
	Username string
	Password string
	Email    string
	IsActive bool
}

// ListUsers : http handler for returning list of users
func ListUsers(w http.ResponseWriter, r *http.Request) {
	list := []User{
		{ID: 1, Username: "jackyhtg", Email: "jacky@htg.com", IsActive: true},
		{ID: 2, Username: "jetlee", Email: "jet@lee.com", IsActive: true},
	}

	data, err := json.Marshal(list)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}

func openDB() (*sql.DB, error) {
	return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}

```

- Jalankan `go run main.go migrate` dan `go run main.go` 

### Seed
- Digunakan untuk dump data users
- Buatlah file schema/seed.go
- Fungsi seed menggunakan fitur transaction, sehingga jika ada query yang gagal akan dirollback semua.
```
// file schema/seed.go
package schema

import "database/sql"

// seeds is a string constant containing all of the queries needed to get the
// db seeded to a useful state for development.
//
// Using a constant in a .go file is an easy way to ensure the queries are part
// of the compiled executable and avoids pathing issues with the working
// directory. It has the downside that it lacks syntax highlighting and may be
// harder to read for some cases compared to using .sql files. You may also
// consider a combined approach using a tool like packr or go-bindata.
//
// Note that database servers besides PostgreSQL may not support running
// multiple queries as part of the same execution so this single large constant
// may need to be broken up.

const seeds = `
INSERT INTO users (username, password, email, is_active) VALUES
	('jackyhtg', '$2y$10$ekouPwVdtMEy5AFbogzfSeRloxHzUwEAsM7SyNJXnso/F9ds/XUYy', 'admin@admin.com', 1)
`

// Seed runs the set of seed-data queries against db. The queries are ran in a
// transaction and rolled back if any fail.
func Seed(db *sql.DB) error {
	tx, err := db.Begin()
	if err != nil {
		return err
	}

	if _, err := tx.Exec(seeds); err != nil {
		if err := tx.Rollback(); err != nil {
			return err
		}
		return err
	}

	return tx.Commit()
}
``` 

- Di file main.go ditambahkan kode untuk menghandle jika ada perintah cli dengan parameter seed.
- Saat ini sudah ada dua perintah cli, yaitu migrate dan seed. Karena dimungkinkan akan ada banyak perintah cli yang lain, maka perintah if akan diganti dengan swicth case saja.
```
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"essentials/schema"
	"flag"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	// =========================================================================
	// App Starting

	log.Printf("main : Started")
	defer log.Println("main : Completed")

	// =========================================================================

	// Start Database

	db, err := openDB()
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

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
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

// User : struct of User
type User struct {
	ID       uint
	Username string
	Password string
	Email    string
	IsActive bool
}

// ListUsers : http handler for returning list of users
func ListUsers(w http.ResponseWriter, r *http.Request) {
	list := []User{
		{ID: 1, Username: "jackyhtg", Email: "jacky@htg.com", IsActive: true},
		{ID: 2, Username: "jetlee", Email: "jet@lee.com", IsActive: true},
	}

	data, err := json.Marshal(list)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}

func openDB() (*sql.DB, error) {
	return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}
```  

- Jalankan perintah `go run main.go seed`

### ListUsers
- Saat ini isi/data dari ListUsers masih di-hardcode. Kini kita akan mengisinya dengan data dari tabel users.
- Ganti handle ListUsers dengan method Users.List
- Buat type Users dengan Db yang diinject dari fungsi utama (dependency injection).
```
//Users : struct for set Users Dependency Injection
type Users struct {
	Db *sql.DB
}
```

- Buat method Users.List
```
//List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
	var list []*User
	const q = `SELECT id, username, password, email, is_active FROM users`

	rows, err := u.Db.Query(q)
	if err != nil {
		log.Printf("error: query selecting users: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	defer rows.Close()

	for rows.Next() {
		var user User
		if err := rows.Scan(&user.ID, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			log.Printf("error: scan users: %s", err)
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		list = append(list, user)
	}

	if err := rows.Err(); err != nil {
		log.Printf("error: Row query users: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	data, err := json.Marshal(list)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}
``` 
- Di fungsi utama, ubah parameter Handler pada server untuk memanggil Users.List
```
service := Users{Db: db}
server := http.Server{
		Addr:         "localhost:9000",
		Handler:      http.HandlerFunc(service.List),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}
``` 
- Berikut hasil akhir dari file main.go
```
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"essentials/schema"
	"flag"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
	// =========================================================================
	// App Starting

	log.Printf("main : Started")
	defer log.Println("main : Completed")

	// =========================================================================

	// Start Database

	db, err := openDB()
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

	// Create variable service with pattern dependency injection.
	// Inject koneksion db to type of Users
	service := Users{Db: db}

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
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

// User : struct of User
type User struct {
	ID       uint
	Username string
	Password string
	Email    string
	IsActive bool
}

//Users : struct for set Users Dependency Injection
type Users struct {
	Db *sql.DB
}

//List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
	var list []*User
	const q = `SELECT id, username, password, email, is_active FROM users`

	rows, err := u.Db.Query(q)
	if err != nil {
		log.Printf("error: query selecting users: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	defer rows.Close()

	for rows.Next() {
		var user User
		if err := rows.Scan(&user.ID, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			log.Printf("error: scan users: %s", err)
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		list = append(list, user)
	}

	if err := rows.Err(); err != nil {
		log.Printf("error: Row quer users: %s", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	data, err := json.Marshal(list)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}

func openDB() (*sql.DB, error) {
	return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}

```

## Clean architecture

![](./clean-architecture.jpg)

- Terdiri dari 3 layer: presentaion layer, domain layer, dan data layer.
- Presentation layer meliputi : routing, payload request dan payload response.
- Domain layer meliputi : use case yang berisi interaction dan logic
- Data layer meliputi : entity / model 
- Untuk mengadopsi clean architecture, dibuat struktur direktori aplikasi sebagai berikut :
```
> cmd
> controllers
> libraries
> models
> payloads
    > request
    > response
> schema
> usecases
```
- Implementasi tidak harus kaku. Tidak semua endpoint harus ada use case. Pattern use case digunakan jika mengandung banyak logic, atau melibatkan banyak model. Jika endpoint sederhana dengan hanya 1 model, tidak perlu membuat use case.
- Dalam contoh list user, karena masih sederhana, kita tidak akan menggunakan use case.
- Kita akan memecah kode di file main.go menjadi 5 file yaitu :
```
main.go -> berisi kode untuk handling start up dan shutdown 
cmd/main.go -> berisi kode untuk handling console command, yaitu migrate dan seed
libraries/database/database.go -> berisi kode untuk membuat koneksi database
controllers/users.go -> berisi struct Users dan method List handler 
models/user.go -> berisi struct User dan method List untuk mendapatkan data list user dari database
payloads/response/user_response.go -> Format json response dari list user
``` 
- Berikut isi dari file libraries/database/database.go
```
package database

import "database/sql"

//Open : open database
func Open() (*sql.DB, error) {
	return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}

```

- Berikut isi dari file cmd/main.go
```
package main

import (
	"essentials/libraries/database"
	"essentials/schema"
	"flag"
	"log"
	"os"

	_ "github.com/go-sql-driver/mysql"
)

func main() {
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

- Berikut isi dari file models/user.go
```
package models

import (
	"database/sql"
)

// User : struct of User
type User struct {
	ID       uint
	Username string
	Password string
	Email    string
	IsActive bool
}

const qUser = `SELECT id, username, password, email, is_active FROM users`

// List of users
func (u *User) List(db *sql.DB) ([]User, error) {
	var list []User

	rows, err := db.Query(qUser)
	if err != nil {
		return list, err
	}

	defer rows.Close()

	for rows.Next() {
		var user User
		if err := rows.Scan(&user.ID, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			return list, err
		}
		list = append(list, user)
	}

	return list, rows.Err()
}

```

- Berikut adalah isi dari file payloads/response/user_response.go
```
package response

import "essentials/models"

// UserResponse struct for response of user
type UserResponse struct {
	ID       uint   `json:"id"`
	Username string `json:"username"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

// Transform from models.User to UserResponse
func (u *UserResponse) Transform(user models.User) {
	u.ID = user.ID
	u.Username = user.Username
	u.Email = user.Email
	u.IsActive = user.IsActive
}

```

- Berikut isi file controllers/users.go
```
package controllers

import (
	"database/sql"
	"encoding/json"
	"essentials/models"
	"essentials/payloads/response"
	"log"
	"net/http"
)

// Users : struct for set Users Dependency Injection
type Users struct {
	Db *sql.DB
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
	user := new(models.User)
	list, err := user.List(u.Db)
	if err != nil {
		log.Println("error get list user", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	var responseList []response.UserResponse
	for _, l := range list {
		var res response.UserResponse
		res.Transform(l)
		responseList = append(responseList, res)
	}

	data, err := json.Marshal(responseList)
	if err != nil {
		log.Println("error marshalling result", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	if _, err := w.Write(data); err != nil {
		log.Println("error writing result", err)
	}
}

```

- Dan isi dari file main.go adalah :
```
package main

import (
	"context"
	"essentials/controllers"
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
		Addr:         "0.0.0.0:9000",
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

## Configuration
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
_, ok := os.LookupEnv("APP_ENV")
if !ok {
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
	_, ok := os.LookupEnv("APP_ENV")
	if !ok {
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