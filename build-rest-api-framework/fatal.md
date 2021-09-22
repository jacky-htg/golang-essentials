# Fatal

* log.Fatal menyebabkan program berhenti setelah mencetak sebuah pesan error
* log.Fatal memanggil fungsi os.Exit\(1\) untuk memaksa program berhenti
* Penggunaan log.Fatal untuk menghentikan program sedini mungkin jika ada suatu kesalahan yang menyebabkan suatu kode selanjutnya tidak perlu dieksekusi sama sekali, atau kesalahan yang tidak dapat dipulihkan.
* log.Fatal biasanya hanya ada di dalam func init\(\) atau func main\(\)
* Dalam bab ini, kita akan memastikan bahwa log.Fatal hanya dipanggil di fungsi main, dan hanya dipanggil 1x. Hal ini dimaksudkan agar lebih mudah dalam mengelola kode dan memeriksa kesalahan-kesalahan fatal.
* Pindahkan semua kode main ke `func run() error{}`. 
* Hapus seluruh call log.Fatal dan diganti dengan return error
* Panggil fungsi run\(\) di main\(\), jika terjadi error eksekusi log.Fatal

```go
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

* File cmd/main.go berubah menjadi seperti berikut :

```go
package main

import (
    "essentials/libraries/config"
    "essentials/libraries/database"
    "essentials/schema"
    "flag"
    "fmt"
    "log"
    "os"

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

    // Start Database
    db, err := database.Open()
    if err != nil {
        return fmt.Errorf("connecting to db: %v", err)
    }
    defer db.Close()

    // Handle cli command
    flag.Parse()

    switch flag.Arg(0) {
    case "migrate":
        if err := schema.Migrate(db); err != nil {
            return fmt.Errorf("applying migrations: %v", err)
        }
        log.Println("Migrations complete")

    case "seed":
        if err := schema.Seed(db); err != nil {
            return fmt.Errorf("seeding database: %v", err)
        }
        log.Println("Seed data complete")
    }

    return nil
}
```

