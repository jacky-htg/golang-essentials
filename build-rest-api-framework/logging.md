# Logging

* Saat ini kita menggunakan log global. Ini sebuah variabel di level paket.
* Jangan gunakan variabel global, seperti variabel di level paket.
* Solusinya, kita akan melewatkan pointer \*log.Logger ke paket yang membutuhkannya melalui pattern dependency injection.
* Create variabel log \(pointer\) di awal `func run()` di file main.go

```text
    // =========================================================================
    // Logging
    log := log.New(os.Stdout, "Essentials : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)
```

* Semua pemakaian log akan menggunakan pointer log yang telah dibuat.
* Lewatkan pointer log ke service yang membutuhkan dengan pattern dependency injection

```text
service := controllers.Users{Db: db, Log: log}
```

* File main.go berubah menjadi 

```text
package main

import (
    "context"
    "essentials/controllers"
    "essentials/libraries/config"
    "essentials/libraries/database"
    "fmt"
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

    if err := run(); err != nil {
        log.Printf("error: shutting down: %s", err)
        os.Exit(1)
    }
}

func run() error {
    // =========================================================================
    // Logging
    log := log.New(os.Stdout, "Essentials : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

    // =========================================================================
    // App Starting

    log.Printf("main : Started")
    defer log.Println("main : Completed")

    // =========================================================================

    // Start Database

    db, err := database.Open()
    if err != nil {
        return fmt.Errorf("connecting to db: %v", err)
    }
    defer db.Close()

    // Create variable service with pattern dependency injection.
    // Inject koneksion db to type of Users
    service := controllers.Users{Db: db, Log: log}

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
        return fmt.Errorf("Starting server: %v", err)

    case <-shutdown:
        log.Println("caught signal, shutting down")

        // Jika ada shutdown, meminta tambahan waktu 5 detik untuk menyelesaikan proses yang sedang berjalan.
        const timeout = 5 * time.Second
        ctx, cancel := context.WithTimeout(context.Background(), timeout)
        defer cancel()

        if err := server.Shutdown(ctx); err != nil {
            log.Printf("main : Graceful shutdown did not complete in %v : %v", timeout, err)
            if err := server.Close(); err != nil {
                return fmt.Errorf("could not stop server gracefully: %v", err)
            }
        }
    }

    return nil
}
```

* File controllers/users.go berubah menjadi 

```text
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
    Db  *sql.DB
    Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
    user := new(models.User)
    list, err := user.List(u.Db)
    if err != nil {
        u.Log.Println("error get list user", err)
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
        u.Log.Println("error marshalling result", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    w.Header().Set("Content-Type", "application/json; charset=utf-8")
    w.WriteHeader(http.StatusOK)
    if _, err := w.Write(data); err != nil {
        u.Log.Println("error writing result", err)
    }
}
```

