# Database

Database yang digunakan dalam materi ini adalah database mysql versi 8. Query-query yang digunakan akan memaksimalkan query native pada paket database/sql bawaan golang. Pertimbangannya, paket database/sql sudah cukup mudah untuk digunakan, dan performancenya sangat baik.

Pembahasan database dibagi dalam 3 bahasan, yaitu: pembuatan migration, seed dan implementasi ListUsers dimana datanya diambil dari database.

* Langkah pertama adalah membuat database. Buka mysql dan buatlah schema : "essentials"
* Kemudian buat koneksi database dengan membuat fungsi openDb\(\). Misalkan user=root dan password=pass.

```go
func openDB() (*sql.DB, error) {
    return sql.Open("mysql", "root:pass@tcp(localhost:3306)/essentials?parseTime=true")
}
```

## Migration

* Ada banyak library yang mengerjakan proses migration. Kali ini saya akan menggunakan library [darwin](https://github.com/GuiaBolso/darwin) karena cukup simple.
* Buat folder schema. Kemudian buatlan file schema/migrate.go yang isinya sebagai berikut :

```go
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

* Di file main.go akan dilakukan perubahan agar mampu mendukung dua perintah, yaitu perintah listenAndServe http serta perintah migrate yang jalan di console. Perintah flag.Parse\(\) digunakan untuk menangkap parsing parameter yang dilempar dari console.
* Kemudian bandingkan argumen yang diterima, jika argumen == migrate maka eksekusi schema/Migrate\(\) dan setelah selesai diberi perintah return agar baris kode selanjutnya tidak dieksekusi.
* Selain argumen == migrate, akan diabaikan sehingga baris kode yang dijalankan adalah baris http listenAndServe.
* Karena shema/Migrate\(\) membutuhkan koneksi database, maka di awal fungsi utama akan dilakukan pemanggilan fungsi koneksi database.
* Jangan lupa untuk mengimport paket essentials/schema dan \_ "github.com/go-sql-driver/mysql"

```go
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

* Jalankan `go run main.go migrate` dan `go run main.go` 

## Seed

* Digunakan untuk dump data users
* Buatlah file schema/seed.go
* Fungsi seed menggunakan fitur transaction, sehingga jika ada query yang gagal akan dirollback semua.

```go
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

* Di file main.go ditambahkan kode untuk menghandle jika ada perintah cli dengan parameter seed.
* Saat ini sudah ada dua perintah cli, yaitu migrate dan seed. Karena dimungkinkan akan ada banyak perintah cli yang lain, maka perintah if akan diganti dengan swicth case saja.

```go
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

* Jalankan perintah `go run main.go seed`

## ListUsers

* Saat ini isi/data dari ListUsers masih di-hardcode. Kini kita akan mengisinya dengan data dari tabel users.
* Ganti handle ListUsers dengan method Users.List
* Buat type Users dengan Db yang diinject dari fungsi utama \(dependency injection\).

```go
//Users : struct for set Users Dependency Injection
type Users struct {
    Db *sql.DB
}
```

* Buat method Users.List

```go
//List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
    var list []User
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

* Di fungsi utama, ubah parameter Handler pada server untuk memanggil Users.List

```go
service := Users{Db: db}
server := http.Server{
        Addr:         "localhost:9000",
        Handler:      http.HandlerFunc(service.List),
        ReadTimeout:  5 * time.Second,
        WriteTimeout: 5 * time.Second,
    }
```

* Berikut hasil akhir dari file main.go

```go
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

